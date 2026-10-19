<?php

namespace Sfere\Exceptions;

use RuntimeException;

/**
 * Base class for every exception the SDK throws. Only thrown when `throw_exceptions` is true.
 */
class SfereException extends RuntimeException {}
