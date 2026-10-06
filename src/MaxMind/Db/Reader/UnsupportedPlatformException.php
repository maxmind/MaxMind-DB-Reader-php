<?php

declare(strict_types=1);

namespace MaxMind\Db\Reader;

/**
 * The platform cannot decode a database value or offset.
 */
// phpcs:ignore PSR2.Classes.ClassDeclaration, Squiz.WhiteSpace.ScopeClosingBrace.ContentBefore
class UnsupportedPlatformException extends \RuntimeException {}
