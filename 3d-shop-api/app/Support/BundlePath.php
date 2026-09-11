<?php

namespace App\Support;

/**
 * A texture or buffer a model names, resolved against the bundle it arrived in.
 * Inside the bundle or nowhere: an absolute path names the artist's own disk,
 * and a relative one that climbs out names ours.
 */
class BundlePath
{
    public static function inside(string $name, string $from, string $root): ?string
    {
        $name = trim(str_replace('\\', '/', $name), " \t\"'");

        if ($name === '' || preg_match('#^([a-zA-Z]:|/|\\\\)#', $name) === 1) {
            return null;
        }

        $path = realpath($from.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($name)));
        $base = realpath($root);

        if ($path === false || $base === false || ! is_file($path)) {
            return null;
        }

        return $path === $base || str_starts_with($path, $base.DIRECTORY_SEPARATOR) ? $path : null;
    }
}
