<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A page must never export `SomePage.layout = {}`.
 *
 * Inertia reads Page.layout three ways: a component, a named-layout map, or a
 * props object for the default layout. An EMPTY object is classified as a
 * named-layout map with no entries -- Object.values({}).every(...) is vacuously
 * true -- so isPropsObject() returns false, the layout list normalises to
 * empty, and the entire shell silently disappears.
 *
 * The symptom is not an error. The page renders, unwrapped, on the bare body
 * background: no header, no sidebar, no card. It looks like a CSS failure and
 * sends you hunting through stylesheets. This is a grep because that is all it
 * takes to never lose that afternoon again.
 *
 * To opt a page out of its layout, omit the property entirely (the default
 * layout applies) or return null from the resolver in app.tsx.
 */
class InertiaLayoutContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_page_exports_an_empty_layout_object(): void
    {
        $offenders = [];

        foreach ($this->pageFiles() as $file) {
            $source = (string) file_get_contents($file);

            // `Foo.layout = {}` with any whitespace or newlines between braces.
            if (preg_match('/\.layout\s*=\s*\{\s*\}/', $source) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These pages export an empty `layout` object, which makes Inertia drop the layout entirely.\n"
            ."Omit the property instead.\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * The client's auth artwork is a light blue gradient with white text on it.
     * Inverted it is unreadable, so those pages opt out of the theme in the
     * Blade shell rather than in React -- a class removed after hydration is a
     * visible flash of dark before the login card appears.
     */
    public function test_the_auth_screens_opt_out_of_dark_mode_before_first_paint(): void
    {
        foreach (['/login', '/register', '/forgot-password'] as $uri) {
            $html = $this->withCookie('appearance', 'dark')->get($uri)->getContent();

            $this->assertMatchesRegularExpression(
                '/<html[^>]*class="(?![^"]*\bdark\b)[^"]*"/',
                (string) $html,
                "{$uri} carries the dark class.",
            );
        }
    }

    /**
     * `color-scheme` is an INHERITED property the browser uses to paint its own
     * surfaces -- autofilled inputs, scrollbars, text selection. Removing only
     * the `dark` class left it declaring dark, which painted black autofilled
     * fields inside the white login card and no Tailwind class could override.
     */
    public function test_the_auth_screens_declare_a_light_colour_scheme(): void
    {
        foreach (['/login', '/register'] as $uri) {
            $html = (string) $this->withCookie('appearance', 'dark')
                ->get($uri)
                ->getContent();

            $this->assertStringContainsString('data-force-light', $html, $uri);
            $this->assertStringContainsString('color-scheme: light', $html, $uri);
        }
    }

    public function test_the_signed_in_app_still_honours_the_theme(): void
    {
        $user = User::factory()->create();

        $html = (string) $this->actingAs($user)
            ->withCookie('appearance', 'dark')
            ->get('/dashboard')
            ->getContent();

        // The opt-out is scoped to the light-only pages; removing it everywhere
        // would silently delete the appearance setting.
        $this->assertStringNotContainsString('data-force-light', $html);
        $this->assertStringContainsString('color-scheme: normal', $html);
    }

    /** @return list<string> */
    private function pageFiles(): array
    {
        $directory = resource_path('js/pages');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'tsx') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        // A guard that silently matches nothing is worse than no guard.
        $this->assertNotEmpty($files, 'No page components were found to check.');

        return $files;
    }
}
