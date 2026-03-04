<?php

/**
 * functions.php
 *
 * Backward-compatible loader. All functions are now split into dedicated files.
 * This file exists so any existing require_once of functions.php continues to work.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/helpers.php';
