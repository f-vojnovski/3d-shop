<?php

namespace App\Support;

/**
 * One list of the model formats the site sells. Every validation rule, route
 * pattern and upload field is derived from it, so a format is one entry.
 */
class ModelFormats
{
    /** Sniffed names mapped to the extensions a seller may upload for them. */
    private const EXTENSIONS = [
        'obj' => ['obj'],
        'gltf' => ['gltf', 'glb'],
        'stl' => ['stl'],
        'fbx' => ['fbx'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::EXTENSIONS);
    }

    public static function supports(string $format): bool
    {
        return array_key_exists($format, self::EXTENSIONS);
    }

    /** @return list<string> */
    public static function extensionsFor(string $format): array
    {
        return self::EXTENSIONS[$format] ?? [];
    }

    /** The request field carrying this format's file. */
    public static function field(string $format): string
    {
        return $format.'Model';
    }

    /** @return array<string, string> format => request field */
    public static function fields(): array
    {
        $fields = [];

        foreach (self::all() as $format) {
            $fields[$format] = self::field($format);
        }

        return $fields;
    }

    /** `in:` and `extensions:` take comma-separated lists. */
    public static function rule(): string
    {
        return implode(',', self::all());
    }

    public static function extensionRule(string $format): string
    {
        return implode(',', self::extensionsFor($format));
    }

    /**
     * What a seller may name their upload. Wider than the format's own
     * extensions because a model may arrive zipped with its textures; what is
     * actually inside is decided from the bytes, not from this.
     */
    public static function uploadExtensionRule(string $format): string
    {
        return implode(',', [...self::extensionsFor($format), 'zip']);
    }

    /** Route constraints take a regex alternation. */
    public static function pattern(): string
    {
        return implode('|', self::all());
    }
}
