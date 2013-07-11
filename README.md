# MaxMind DB Reader PHP API #

## Beta Note ##

This is a beta release. The API may change before the first production
release.

To provide feedback or get support during the beta, please see the
[MaxMind Customer Community](https://getsatisfaction.com/maxmind).

## Description ##

## Installation ##

### Define Your Dependencies ###

We recommend installing this package with [Composer](http://getcomposer.org/).
To do this, add ```maxminddb/reader``` to your ```composer.json``` file.

```json
{
    "require": {
        "maxminddb/reader": "0.1.*"
    }
}
```

### Install Composer ###

Run in your project root:

```
curl -s http://getcomposer.org/installer | php
```

### Install Dependencies ###

Run in your project root:

```
php composer.phar install
```

### Require Autoloader ###

You can autoload all dependencies by adding this to your code:
```
require 'vendor/autoload.php';
```

## Usage ##

## Example ##

```php
<?php
require_once 'vendor/autoload.php';

...
```

## Support ##

Please report all issues with this code using the
[GitHub issue tracker](https://github.com/maxmind/GeoIP2-php/issues).

If you are having an issue with a MaxMind service that is not specific
to the client API, please see
[our support page](http://www.maxmind.com/en/support).

## Requirements  ##

This code requires PHP 5.3 or greater. Older versions of PHP are not
supported.

This library also relies on the [Guzzle HTTP client](http://guzzlephp.org/).

## Contributing ##

Patches and pull requests are encouraged. All code should follow the
PSR-2 style guidelines. Please include unit tests whenever possible.

## Versioning ##

The MaxMind DB Reader PHP API uses [Semantic Versioning](http://semver.org/).

## Copyright and License ##

This software is Copyright (c) 2013 by MaxMind, Inc.

This is free software, licensed under the GNU Lesser General Public License
version 2.1 or later.
