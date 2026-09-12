<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The proxy is the one real model file a buyer gets before paying, so what it
 * may contain is a product decision rather than a detail of the exporter.
 *
 * The container checks each built proxy against an allow-list and refuses one
 * carrying more. These tests guard the list itself: widening it, or unhooking
 * any of the steps that clean a model, has to be deliberate.
 */
class ProxyContentsTest extends TestCase
{
    public function test_the_allow_list_refuses_rigs_and_motion(): void
    {
        $allowed = $this->allowList('top');

        $this->assertNotContains('skins', $allowed);
        $this->assertNotContains('animations', $allowed);
        $this->assertNotContains('cameras', $allowed);
    }

    public function test_the_allow_list_refuses_per_vertex_bone_weights(): void
    {
        $allowed = $this->allowList('attribute');

        $this->assertContains('POSITION', $allowed);
        $this->assertNotContains('JOINTS_0', $allowed);
        $this->assertNotContains('WEIGHTS_0', $allowed);
    }

    /** Bone names alone say which program a character was rigged in. */
    public function test_the_allow_list_refuses_names_and_loose_author_data(): void
    {
        foreach (['node', 'mesh', 'material'] as $level) {
            $allowed = $this->allowList($level);

            $this->assertNotContains('name', $allowed, "{$level} may not carry a name");
            $this->assertNotContains('extras', $allowed, "{$level} may not carry extras");
        }

        $this->assertNotContains('skin', $this->allowList('node'));
    }

    public function test_a_proxy_is_cleaned_before_it_is_exported(): void
    {
        $page = $this->render('proxy.html');

        foreach (['simplifyObject', 'shrinkTextures', 'bakeSkinning', 'stripIdentity'] as $step) {
            $this->assertStringContainsString($step.'(', $page, "{$step} no longer runs");
        }
    }

    public function test_the_built_proxy_is_checked_before_it_is_written(): void
    {
        $runner = $this->render('proxy.mjs');

        $this->assertStringContainsString('leaksIn(state.glb)', $runner);
        $this->assertMatchesRegularExpression(
            '/leaks\.length > 0[\s\S]{0,400}?status: .failed./',
            $runner,
            'a proxy carrying more than it may is no longer refused'
        );
    }

    /**
     * The seller approves a proxy on a slider before it is built. If the two
     * shrink differently, what they signed off is not what a buyer receives.
     */
    public function test_the_seller_preview_shrinks_with_the_same_module(): void
    {
        $shared = 'model-displayer/shrinkTextures.js';

        $this->assertStringContainsString($shared, $this->file('render/Dockerfile'));
        $this->assertStringContainsString('shrinkTextures.js', $this->render('proxy.mjs'));
        $this->assertStringContainsString(
            "from './shrinkTextures'",
            $this->client('ProxyPreview.jsx')
        );
    }

    /** One cap, in the shared module, or the two sides drift apart silently. */
    public function test_the_texture_cap_is_stated_once(): void
    {
        $this->assertMatchesRegularExpression(
            '/export const PROXY_TEXTURE_MAX = \d+;/',
            $this->client('shrinkTextures.js')
        );
    }

    /** @return list<string> */
    private function allowList(string $level): array
    {
        preg_match(
            '/^const ALLOWED = \{(.+?)^\};/ms',
            $this->render('proxy.mjs'),
            $block
        );

        $this->assertNotEmpty($block, 'the proxy allow-list is gone');

        preg_match('/\b'.$level.':\s*\[(.*?)\]/s', $block[1], $entry);

        $this->assertNotEmpty($entry, "the allow-list has no {$level} entry");

        preg_match_all("/'([^']+)'/", $entry[1], $names);

        return $names[1];
    }

    private function render(string $name): string
    {
        return $this->file('render/'.$name);
    }

    private function client(string $name): string
    {
        return (string) file_get_contents(
            base_path('../3d-shop-client/src/components/common/model-displayer/'.$name)
        );
    }

    private function file(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
