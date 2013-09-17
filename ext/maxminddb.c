#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "php_maxminddb.h"

#if PHP_VERSION_ID < 50399
# define object_properties_init(zo, class_type)         \
    {                                                   \
        zval *tmp;                                      \
        zend_hash_copy((*zo).properties,                \
                       &class_type->default_properties, \
                       (copy_ctor_func_t)zval_add_ref,  \
                       (void *)&tmp,                    \
                       sizeof(zval *));                 \
    }
#endif

static zend_object_handlers maxminddb_obj_handlers;
static zend_class_entry *maxminddb_ce;

static int throw_exception(char *exception_name TSRMLS_DC, char *message, ...)
{
    char *error;
    va_list args;
    zend_class_entry **exception_ce;

    if (FAILURE ==
        zend_lookup_class(exception_name, strlen(exception_name),
                          &exception_ce TSRMLS_CC)) {
        zend_error(E_ERROR, "Class %s not found", exception_name);
    }

    va_start(args, message);
    vspprintf(&error, 0, message, args);
    va_end(args);

    if (!error ) {
        zend_error(E_ERROR, "Out of memory");
    }
    zend_throw_exception(*exception_ce,
                         error, 0 TSRMLS_CC);
    efree(error);
    return;
}

static int entry_data(MMDB_entry_data_list_s **entry_data_list,
                      zval *z_value TSRMLS_DC)
{
    bool get_next = true;

    switch ((*entry_data_list)->entry_data.type) {
    case MMDB_DATA_TYPE_MAP:
        {
            get_next = false;
            array_init(z_value);

            uint32_t map_size = (*entry_data_list)->entry_data.data_size;
            (*entry_data_list) = (*entry_data_list)->next;

            int i;
            for (i = 0; i < map_size; i++ ) {
                char *key =
                    estrndup((char *)(*entry_data_list)->entry_data.utf8_string,
                             (*entry_data_list)->entry_data.data_size);
                if (NULL == key) {
                    throw_exception(PHP_MAXMINDDB_READER_EX_NS TSRMLS_CC,
                                    "Invalid data type arguments");
                }

                (*entry_data_list) = (*entry_data_list)->next;
                zval *new_value;
                ALLOC_INIT_ZVAL(new_value);
                entry_data(entry_data_list, new_value TSRMLS_CC);
                add_assoc_zval(z_value, key, new_value);
            }
        }
        break;
    case MMDB_DATA_TYPE_ARRAY:
        {
            get_next = false;
            uint32_t size = (*entry_data_list)->entry_data.data_size;

            array_init(z_value);

            for ((*entry_data_list) = (*entry_data_list)->next;
                 size && (*entry_data_list); size--) {
                zval *new_value;
                ALLOC_INIT_ZVAL(new_value);
                entry_data(entry_data_list, new_value TSRMLS_CC);
                add_next_index_zval(z_value, new_value);
            }
        }
        break;
    case MMDB_DATA_TYPE_UTF8_STRING:
        {
            ZVAL_STRINGL(z_value,
                         (char *)(*entry_data_list)->entry_data.utf8_string,
                         (*entry_data_list)->entry_data.data_size,
                         1);
        }
        break;
    case MMDB_DATA_TYPE_BYTES:
        {
            ZVAL_STRINGL(z_value, (char *)(*entry_data_list)->entry_data.bytes,
                         (*entry_data_list)->entry_data.data_size, 1);
        }
        break;
    case MMDB_DATA_TYPE_DOUBLE:
        ZVAL_DOUBLE(z_value, (*entry_data_list)->entry_data.double_value);
        break;
    case MMDB_DATA_TYPE_FLOAT:
        ZVAL_DOUBLE(z_value, (*entry_data_list)->entry_data.float_value);
        break;
    case MMDB_DATA_TYPE_UINT16:
        ZVAL_LONG(z_value, (*entry_data_list)->entry_data.uint16);
        break;
    case MMDB_DATA_TYPE_UINT32:
        ZVAL_LONG(z_value, (*entry_data_list)->entry_data.uint32);
        break;
    case MMDB_DATA_TYPE_BOOLEAN:
        ZVAL_BOOL(z_value, (*entry_data_list)->entry_data.boolean);
        break;
    case MMDB_DATA_TYPE_UINT64:
        {
            // We return it as a string because PHP uses signed longs
            char *int_str;
            spprintf(&int_str, 0, "%" PRIu64,
                     (*entry_data_list)->entry_data.uint64 );
            ZVAL_STRING(z_value, int_str, 0);
        }
        break;
    case MMDB_DATA_TYPE_UINT128:
        {
            mpz_t integ;
            mpz_init(integ);

            int i;
            for (i = 0; i < 16; i++) {
                mpz_t part;
                mpz_init(part);
                mpz_set_ui(part, (*entry_data_list)->entry_data.uint128[i]);

                mpz_mul_2exp(integ, integ, 8);
                mpz_add(integ, integ, part);
            }
            char *num_str = mpz_get_str(NULL, 10, integ);
            ZVAL_STRING(z_value, num_str, 1);
            efree(num_str);
        }
        break;
    case MMDB_DATA_TYPE_INT32:
        ZVAL_LONG(z_value, (*entry_data_list)->entry_data.int32);
        break;
    default:
        {
            throw_exception(PHP_MAXMINDDB_READER_EX_NS TSRMLS_CC,
                            "Invalid data type arguments: %d",
                            (*entry_data_list)->entry_data.type);
        }
    }
    if (get_next && *entry_data_list) {
        (*entry_data_list) = (*entry_data_list)->next;
    }
    return 0;
}

PHP_METHOD(MaxMind_Db_Reader, __construct){
    char *db_file = NULL;
    int name_len;
    maxminddb_obj *mmdb_obj;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &db_file,
                              &name_len) == FAILURE) {
        throw_exception("InvalidArgumentException" TSRMLS_CC,
                        "Invalid arguments");
    }

    MMDB_s *mmdb = (MMDB_s *)emalloc(sizeof(MMDB_s));
    uint16_t status = MMDB_open(db_file, MMDB_MODE_MMAP, mmdb);

    if (MMDB_SUCCESS != status) {
        throw_exception(PHP_MAXMINDDB_READER_EX_NS TSRMLS_CC,
                        "Error opening database");
        return;
    }

    mmdb_obj = zend_object_store_get_object(getThis() TSRMLS_CC);
    mmdb_obj->mmdb = mmdb;
}

PHP_METHOD(MaxMind_Db_Reader, get){
    char *ip_address = NULL;
    int name_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &ip_address,
                              &name_len) == FAILURE) {
        throw_exception("InvalidArgumentException" TSRMLS_CC,
                        "Invalid arguments");
    }

    maxminddb_obj *mmdb_obj = (maxminddb_obj *)zend_object_store_get_object(
        getThis() TSRMLS_CC);

    MMDB_s *mmdb = mmdb_obj->mmdb;

    int gai_error = MMDB_SUCCESS;
    int mmdb_error = MMDB_SUCCESS;
    MMDB_lookup_result_s result =
        MMDB_lookup_string(mmdb, ip_address, &gai_error,
                           &mmdb_error);

    if (MMDB_SUCCESS != gai_error) {
        throw_exception("DomainException" TSRMLS_CC,
                        "Error resolving %s", ip_address);
    }

    if (MMDB_SUCCESS != mmdb_error) {
        throw_exception(PHP_MAXMINDDB_READER_EX_NS TSRMLS_CC,
                        "Error looking up %s", ip_address);
    }

    MMDB_entry_data_list_s *entry_data_list = NULL;

    zval *z_value;
    if (result.found_entry) {
        int status = MMDB_get_entry_data_list(&result.entry, &entry_data_list);

        if (MMDB_SUCCESS != status) {
            throw_exception(PHP_MAXMINDDB_READER_EX_NS TSRMLS_CC,
                            "Error while looking up data for %s", ip_address);
        }

        if (NULL != entry_data_list) {
            entry_data(&entry_data_list, return_value TSRMLS_CC);
        }
    } else {
        RETURN_NULL();
    }

    return;
}

PHP_METHOD(MaxMind_Db_Reader, metadata){
    maxminddb_obj *mmdb_obj = (maxminddb_obj *)zend_object_store_get_object(
        getThis() TSRMLS_CC);

    zend_class_entry **metadata_ce;
    char *name = ZEND_NS_NAME(PHP_MAXMINDDB_READER_NS, "Metadata");
    if (FAILURE ==
        zend_lookup_class(name, strlen(name),
                          &metadata_ce TSRMLS_CC)) {
        zend_error(E_ERROR, "Class %s not found", name);
        return;
    }
    object_init_ex(return_value, *metadata_ce);

    zval *metadata_array;
    ALLOC_INIT_ZVAL(metadata_array);

    MMDB_entry_data_list_s *entry_data_list;
    MMDB_get_metadata_as_entry_data_list(mmdb_obj->mmdb, &entry_data_list);

    entry_data(&entry_data_list, metadata_array TSRMLS_CC);
    zend_call_method_with_1_params(&return_value, *metadata_ce,
                                   &(*metadata_ce)->constructor,
                                   ZEND_CONSTRUCTOR_FUNC_NAME,
                                   NULL,
                                   metadata_array);

    return;
}

static void maxminddb_free_storage(void *object TSRMLS_DC)
{
    maxminddb_obj *obj = (maxminddb_obj *)object;
    efree(obj->mmdb);

    zend_hash_destroy(obj->std.properties);
    FREE_HASHTABLE(obj->std.properties);

    efree(obj);
}

static zend_object_value maxminddb_create_handler(
    zend_class_entry *type TSRMLS_DC)
{
    zval *tmp;
    zend_object_value retval;

    maxminddb_obj *obj = (maxminddb_obj *)emalloc(sizeof(maxminddb_obj));
    memset(obj, 0, sizeof(maxminddb_obj));
    obj->std.ce = type;

    ALLOC_HASHTABLE(obj->std.properties);
    zend_hash_init(obj->std.properties, 0, NULL, ZVAL_PTR_DTOR, 0);

    object_properties_init(&(obj->std), type);

    retval.handle = zend_objects_store_put(obj, NULL,
                                           maxminddb_free_storage,
                                           NULL TSRMLS_CC);
    retval.handlers = &maxminddb_obj_handlers;

    return retval;
}


static zend_function_entry maxminddb_methods[] = {
    PHP_ME(MaxMind_Db_Reader, __construct, NULL,
           ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(MaxMind_Db_Reader, get,         NULL,
           ZEND_ACC_PUBLIC)
    PHP_ME(MaxMind_Db_Reader, metadata,    NULL,
           ZEND_ACC_PUBLIC){
        NULL,                 NULL,        NULL
    }
};


PHP_MINIT_FUNCTION(maxminddb){

    zend_class_entry ce;

    INIT_CLASS_ENTRY(ce, ZEND_NS_NAME(PHP_MAXMINDDB_NS,
                                      "Reader"), maxminddb_methods);
    maxminddb_ce = zend_register_internal_class(&ce TSRMLS_CC);
    maxminddb_ce->create_object = maxminddb_create_handler;
    maxminddb_ce->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_ABSTRACT;
    memcpy(&maxminddb_obj_handlers,
           zend_get_std_object_handlers(), sizeof(zend_object_handlers));
    maxminddb_obj_handlers.clone_obj = NULL;

    return SUCCESS;
}

zend_module_entry maxminddb_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_MAXMINDDB_EXTNAME,
    NULL,
    PHP_MINIT(maxminddb),
    NULL,
    NULL,
    NULL,
    NULL,
    PHP_MAXMINDDB_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_MAXMINDDB
ZEND_GET_MODULE(maxminddb)
#endif
