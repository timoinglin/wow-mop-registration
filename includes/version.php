<?php
/**
 * Canonical application version.
 *
 * Bump this ONE line at every release (after merging develop → main,
 * before `git tag`). It's the source of truth the admin "update
 * available" notice compares against the GitHub latest release.
 *
 * `/VERSION` (gitignored, written by update.ps1 after a one-click
 * update) overrides this when it's present AND higher — so installs
 * updated by the script stay accurate without shipping a code change,
 * while git/manual installs still get a correct value from this
 * tracked constant.
 */

if (!defined('WL_VERSION')) {
    define('WL_VERSION', 'v0.7.0');
}
