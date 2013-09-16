#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "php_maxminddb.h"

static zend_object_handlers maxminddb_obj_handlers;
static zend_class_entry *maxminddb_ce;

int throw_exception(char *exception_name, char * message, ...)
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

int entry_data(MMDB_entry_data_list_s **entry_data_list, zval *z_value)
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
                    throw_exception(PHP_MAXMINDDB_READER_EX_NS,
                                    "Invalid data type arguments");
                }

                (*entry_data_list) = (*entry_data_list)->next;
                zval *new_value;
                ALLOC_INIT_ZVAL(new_value);
                entry_data(entry_data_list, new_value);
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
                entry_data(entry_data_list, new_value);
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
            mpz_init (integ);

            int i;
            for (i=0; i < 16; i++) {
                mpz_t part;
                mpz_init (part);
                mpz_set_ui(part, (*entry_data_list)->entry_data.uint128[i]);

                mpz_mul_2exp(integ, integ, 8);
                mpz_add(integ, integ, part);
            }
            char* num_str = mpz_get_str(NULL, 10, integ);
            ZVAL_STRING(z_value, num_str, 1);
            efree(num_str);
        }
        break;
    case MMDB_DATA_TYPE_INT32:
        ZVAL_LONG(z_value, (*entry_data_list)->entry_data.int32);
        break;
    default:
        {
            throw_exception(PHP_MAXMINDDB_READER_EX_NS,
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
        throw_exception("InvalidArgumentException",
                        "Invalid arguments");
    }

    MMDB_s *mmdb = (MMDB_s *)emalloc(sizeof(MMDB_s));
    uint16_t status = MMDB_open(db_file, MMDB_MODE_MMAP, mmdb);

    if (MMDB_SUCCESS != status) {
        throw_exception(PHP_MAXMINDDB_READER_EX_NS,
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
        throw_exception("InvalidArgumentException",
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
        throw_exception("DomainException",
                        "Error resolving %s", ip_address);
    }

    if (MMDB_SUCCESS != mmdb_error) {
        throw_exception(PHP_MAXMINDDB_READER_EX_NS,
                        "Error looking up %s", ip_address);
    }

    MMDB_entry_data_list_s *entry_data_list = NULL;

    zval *z_value;
    if (result.found_entry) {
        int status = MMDB_get_entry_data_list(&result.entry, &entry_data_list);

        if (MMDB_SUCCESS != status) {
            throw_exception(PHP_MAXMINDDB_READER_EX_NS,
                            "Error while looking up data for %s", ip_address);
        }

        if (NULL != entry_data_list) {
            entry_data(&entry_data_list, return_value);
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
    array_init(metadata_array);

    // XXX - replace with map from libmaxminddb when it supports it
    zval *major_version;
    ALLOC_INIT_ZVAL(major_version);
    ZVAL_LONG(major_version,
              mmdb_obj->mmdb->metadata.binary_format_major_version)
    add_assoc_zval(metadata_array, "binary_format_major_version", major_version);

    zval *minor_version;
    ALLOC_INIT_ZVAL(minor_version);
    ZVAL_LONG(minor_version,
              mmdb_obj->mmdb->metadata.binary_format_minor_version)
    add_assoc_zval(metadata_array, "binary_format_minor_version", minor_version);

    zval *build_epoch;
    ALLOC_INIT_ZVAL(build_epoch);
    ZVAL_LONG(build_epoch, mmdb_obj->mmdb->metadata.build_epoch)
    add_assoc_zval(metadata_array, "build_epoch", build_epoch);

    zval *ip_version;
    ALLOC_INIT_ZVAL(ip_version);
    ZVAL_LONG(ip_version, mmdb_obj->mmdb->metadata.ip_version)
    add_assoc_zval(metadata_array, "ip_version", ip_version);

    zval *record_size;
    ALLOC_INIT_ZVAL(record_size);
    ZVAL_LONG(record_size, mmdb_obj->mmdb->metadata.record_size)
    add_assoc_zval(metadata_array, "record_size", record_size);

    zval *node_count;
    ALLOC_INIT_ZVAL(node_count);
    ZVAL_LONG(node_count, mmdb_obj->mmdb->metadata.node_count)
    add_assoc_zval(metadata_array, "node_count", node_count);

    zval *database_type;
    ALLOC_INIT_ZVAL(database_type);
    char * db_type_str = mmdb_obj->mmdb->metadata.database_type;
    ZVAL_STRING(database_type, db_type_str, strlen(db_type_str));
    add_assoc_zval(metadata_array, "database_type", database_type);


    zval *descriptions;
    ALLOC_INIT_ZVAL(descriptions);
    array_init(descriptions);
    size_t i;
    for (i = 0; i < mmdb_obj->mmdb->metadata.description.count; i++) {
        zval *description;
        ALLOC_INIT_ZVAL(description);
        const char * description_str =
            mmdb_obj->mmdb->metadata.description.descriptions[i]->description;
        ZVAL_STRING(description, description_str, strlen(description_str));
        add_assoc_zval(
            descriptions,
            mmdb_obj->mmdb->metadata.description.descriptions[i]->language,
            description);
    }
    add_assoc_zval(metadata_array, "description", descriptions);

    zval *languages;
    ALLOC_INIT_ZVAL(languages);
    array_init(languages);
    for (i = 0; i < mmdb_obj->mmdb->metadata.languages.count; i++) {
        zval *language;
        ALLOC_INIT_ZVAL(language);
        char * language_str = mmdb_obj->mmdb->metadata.languages.names[i];
        ZVAL_STRING(language, language_str, strlen(language_str));
        add_next_index_zval(languages, language);
    }
    add_assoc_zval(metadata_array, "languages", languages);

    // END XXX

    zend_call_method_with_1_params(&return_value, *metadata_ce,
                                   &(*metadata_ce)->constructor,
                                   ZEND_CONSTRUCTOR_FUNC_NAME,
                                   NULL,
                                   metadata_array);

    return;
}

void maxminddb_free_storage(void *object TSRMLS_DC)
{
    maxminddb_obj *obj = (maxminddb_obj *)object;
    efree(obj->mmdb);

    zend_hash_destroy(obj->std.properties);
    FREE_HASHTABLE(obj->std.properties);

    efree(obj);
}

zend_object_value maxminddb_create_handler(zend_class_entry *type TSRMLS_DC)
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
    PHP_ME(MaxMind_Db_Reader, __construct,          NULL,
           ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(MaxMind_Db_Reader, get,                  NULL,
           ZEND_ACC_PUBLIC)
    PHP_ME(MaxMind_Db_Reader, metadata,             NULL,
           ZEND_ACC_PUBLIC){
        NULL,                 NULL,                 NULL
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
