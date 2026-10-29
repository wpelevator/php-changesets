<?php

namespace WPElevator\PHP_Changesets;

/**
 * A failed git command, an unexpected failure that exits with code 1 unlike
 * the validation errors of Release_Exception.
 */
final class Git_Exception extends \RuntimeException {}
