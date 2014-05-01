CHANGELOG
=========

0.3.1 (2014-05-01)
------------------

* The API now works when `mbstring.func_overload` is set.
* BCMath is no longer required. If the decoder encounters a big integer,
  it will try to use GMP and then BCMath. If both of those fail, it will
  throw an exception. No databases released by MaxMind currently use big
  integers.
* The API now officially supports HHVM when using the pure PHP reader.

0.3.0 (2014-02-19)
------------------

* This API is now licensed under the Apache License, Version 2.0.
* The code for the C extension was cleaned up, fixing several potential
  issues.

0.2.0 (2013-10-21)
------------------

* Added optional C extension for using libmaxminddb in place of the pure PHP
  reader.
* Significantly improved error handling in pure PHP reader.
* Improved performance for IPv4 lookups in an IPv6 database.

0.1.0 (2013-07-16)
------------------

* Initial release
