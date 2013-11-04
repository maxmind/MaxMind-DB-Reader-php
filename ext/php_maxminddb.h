/* MaxMind, Inc., licenses this file to you under the Apache License, Version
 * 2.0 (the "License"); you may not use this file except in compliance with
 * the License.  You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.  See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <php.h>
#include <zend_interfaces.h>
#include "Zend/zend_exceptions.h"
#include <maxminddb.h>

#ifdef ZTS
#include <TSRM.h>
#endif

#define __STDC_FORMAT_MACROS
#include <inttypes.h>


#ifndef PHP_MAXMINDDB_H
#define PHP_MAXMINDDB_H 1
#define PHP_MAXMINDDB_VERSION "0.2.0"
#define PHP_MAXMINDDB_EXTNAME "maxminddb"
#define PHP_MAXMINDDB_NS ZEND_NS_NAME("MaxMind", "Db")
#define PHP_MAXMINDDB_READER_NS ZEND_NS_NAME(PHP_MAXMINDDB_NS, "Reader")
#define PHP_MAXMINDDB_READER_EX_NS        \
    ZEND_NS_NAME(PHP_MAXMINDDB_READER_NS, \
                 "InvalidDatabaseException")

PHP_FUNCTION(maxminddb);

extern zend_module_entry maxminddb_module_entry;
#define phpext_maxminddb_ptr &maxminddb_module_entry

#endif

typedef struct _maxminddb_obj maxminddb_obj;

struct _maxminddb_obj {
    zend_object std;
    MMDB_s *mmdb;
};
