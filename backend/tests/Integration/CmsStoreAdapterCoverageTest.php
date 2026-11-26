<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CmsMenu;
use App\Models\CmsMenuItem;
use App\Modules\Cms\Domain\CmsConflict;
use App\Modules\Platform\Infrastructure\CmsStoreAdapter;
use App\Support\CommerceScope;
use App\Support\Ids;
use Tests\TestCase;

final class CmsStoreAdapterCoverageTest extends TestCase
{
    private CmsStoreAdapter $cms;

    private CommerceScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cms = new CmsStoreAdapter();
        $this->scope = new CommerceScope('default', 'default', 'en', 'VND');
    }

    public function testPageLifecycleBlocksPreviewPublishedAndRevisions(): void
    {
        $page = $this->cms->create([
            'slug' => 'about-us', 'title' => 'About Us', 'body' => '<p>Hello</p>', 'status' => 'draft',
            'locale' => 'en', 'store_id' => 'default', 'channel_id' => 'default', 'currency' => 'VND',
            'media_json' => json_encode([['url' => '/storage/uploads/hero.png']], JSON_THROW_ON_ERROR),
            'seo_title' => 'About', 'seo_description' => 'About page', 'canonical' => '/about-us',
            'seo_schema_json' => json_encode(['@type' => 'WebPage'], JSON_THROW_ON_ERROR),
        ]);
        $id = (string) $page['id'];
        self::assertCount(1, $this->cms->list($this->scope));
        self::assertNull($this->cms->published('about-us', 'en', $this->scope));

        $publishedBlock = $this->cms->addBlock($id, [
            'type' => 'text', 'content' => 'Published', 'sort' => 2, 'status' => 'published',
            'media_json' => json_encode([['url' => '/storage/uploads/a.png']], JSON_THROW_ON_ERROR),
        ], $this->scope);
        $this->cms->addBlock($id, ['type' => 'text', 'content' => 'Draft', 'sort' => 1, 'status' => 'draft'], $this->scope);
        self::assertNull($this->cms->addBlock('missing-page', ['type' => 'text'], $this->scope));

        $updated = $this->cms->update($id, ['title' => 'About Us v2', 'status' => 'published'], $this->scope);
        self::assertSame('About Us v2', $updated['title'] ?? null);
        self::assertNull($this->cms->update('missing-page', ['title' => 'x'], $this->scope));

        $preview = $this->cms->preview($id, $this->scope);
        self::assertNotNull($preview);
        self::assertCount(2, $preview['blocks']);
        self::assertNull($this->cms->preview('missing-page', $this->scope));

        $published = $this->cms->published('about-us', 'en', $this->scope);
        self::assertNotNull($published);
        self::assertSame('About Us v2', $published['title'] ?? null);
        self::assertCount(1, $published['blocks']);
        self::assertSame('Published', $published['blocks'][0]['content'] ?? null);
        self::assertSame('/storage/uploads/a.png', $published['blocks'][0]['media'][0]['url'] ?? null);
        self::assertSame('/storage/uploads/hero.png', $published['media'][0]['url'] ?? null);
        self::assertSame('About', $published['seo']['title'] ?? null);
        self::assertSame('index,follow', $published['seo']['robots'] ?? null);
        self::assertNull($this->cms->published('missing-slug', 'en', $this->scope));

        $updatedBlock = $this->cms->updateBlock((string) $publishedBlock['id'], ['content' => 'Updated block'], $this->scope);
        self::assertSame('Updated block', $updatedBlock['content'] ?? null);
        self::assertNull($this->cms->updateBlock('missing-block', ['content' => 'x'], $this->scope));
        self::assertNull($this->cms->updateBlock((string) $publishedBlock['id'], ['content' => 'x'], new CommerceScope('other', 'default', 'en', 'VND')));

        $revisions = $this->cms->revisions('page', $id, $this->scope);
        self::assertGreaterThanOrEqual(2, count($revisions));
        $from = (string) $revisions[count($revisions) - 1]['id'];
        $to = (string) $revisions[0]['id'];
        $diff = $this->cms->diff('page', $id, $from, $to, $this->scope);
        self::assertNotEmpty($diff['changes']);
        try {
            $this->cms->diff('page', $id, $from, 'missing-revision', $this->scope);
            self::fail('Missing revisions must be rejected by diff.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('same commerce scope', $error->getMessage());
        }
    }

    public function testAutosaveMergePublishRestoreAndScheduledPublishing(): void
    {
        $page = $this->cms->create([
            'slug' => 'policies', 'title' => 'Policies', 'body' => '<p>v1</p>', 'status' => 'draft',
            'locale' => 'en', 'store_id' => 'default', 'channel_id' => 'default', 'currency' => 'VND',
        ]);
        $id = (string) $page['id'];
        $version = (int) ($page['version'] ?? 1);

        self::assertNull($this->cms->autosave('missing-page', ['title' => 'x'], $this->scope, 'actor-1', 'auto-missing'));
        $autosaved = $this->cms->autosave($id, ['title' => 'Policies v2'], $this->scope, 'actor-1', 'auto-key-1', $version);
        self::assertSame('Policies v2', $autosaved['title'] ?? null);
        self::assertSame('Policies v2', $this->cms->autosave($id, ['title' => 'Ignored'], $this->scope, 'actor-1', 'auto-key-1')['title'] ?? null);
        try {
            $this->cms->autosave($id, ['title' => 'Stale'], $this->scope, 'actor-1', 'auto-key-2', $version);
            self::fail('Stale CMS versions must conflict.');
        } catch (CmsConflict $error) {
            self::assertStringContainsString('changed', $error->getMessage());
        }

        $another = $this->cms->create([
            'slug' => 'another-page', 'title' => 'Another', 'body' => '<p>x</p>', 'status' => 'draft',
            'locale' => 'en', 'store_id' => 'default', 'channel_id' => 'default', 'currency' => 'VND',
        ]);
        try {
            $this->cms->autosave((string) $another['id'], ['title' => 'Reused'], $this->scope, 'actor-1', 'auto-key-1');
            self::fail('Idempotency keys must not be reused across pages.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('another CMS page', $error->getMessage());
        }

        $revisions = $this->cms->revisions('page', $id, $this->scope);
        $revisionId = (string) $revisions[count($revisions) - 1]['id'];
        $merged = $this->cms->merge($id, $revisionId, ['title' => 'Policies merged'], $this->scope, 'actor-2', 'merge-key-1');
        self::assertSame('Policies merged', $merged['title'] ?? null);
        self::assertSame('<p>v1</p>', $merged['body'] ?? null);
        try {
            $this->cms->merge($id, 'missing-revision', ['title' => 'x'], $this->scope, 'actor-2', 'merge-key-2');
            self::fail('Missing revisions must be rejected by merge.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('not found', $error->getMessage());
        }

        self::assertNull($this->cms->publish('missing-page', $this->scope, 'actor-3', 'publish-missing'));
        $published = $this->cms->publish($id, $this->scope, 'actor-3', 'publish-key-1');
        self::assertSame('published', $published['status'] ?? null);
        self::assertSame('published', $this->cms->publish($id, $this->scope, 'actor-3', 'publish-key-1')['status'] ?? null);

        $revisions = $this->cms->revisions('page', $id, $this->scope);
        $restoreFrom = (string) $revisions[count($revisions) - 1]['id'];
        $restored = $this->cms->restore($id, $restoreFrom, $this->scope, 'actor-4', 'restore-key-1');
        self::assertSame('Policies', $restored['title'] ?? null);
        self::assertNull($this->cms->restore('missing-page', $restoreFrom, $this->scope, 'actor-4', 'restore-key-2'));
        try {
            $this->cms->restore($id, 'missing-revision', $this->scope, 'actor-4', 'restore-key-3');
            self::fail('Missing revisions must be rejected by restore.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('not found', $error->getMessage());
        }

        \Siro\Core\Database::table('cms_menus')->insert(['id' => 'menu-footer', 'key' => 'footer', 'locale' => 'en', 'status' => 'published', 'version' => 1]);
        \Siro\Core\Database::table('cms_menu_items')->insert(['id' => 'menu-item-2', 'menu_id' => 'menu-footer', 'label' => 'Second', 'target' => '/second', 'target_type' => 'custom', 'sort' => 2, 'status' => 'published']);
        \Siro\Core\Database::table('cms_menu_items')->insert(['id' => 'menu-item-1', 'menu_id' => 'menu-footer', 'label' => 'First', 'target' => '/first', 'target_type' => 'custom', 'sort' => 1, 'status' => 'published']);
        \Siro\Core\Database::table('cms_menu_items')->insert(['id' => 'menu-item-3', 'menu_id' => 'menu-footer', 'label' => 'Hidden', 'target' => '/hidden', 'target_type' => 'custom', 'sort' => 3, 'status' => 'draft']);
        $found = $this->cms->menu('footer', 'en');
        self::assertNotNull($found);
        self::assertSame('First', $found['items'][0]['label'] ?? null);
        self::assertCount(2, $found['items']);
        self::assertNull($this->cms->menu('missing-menu', 'en'));

        $scheduled = $this->cms->create([
            'slug' => 'scheduled-page', 'title' => 'Scheduled', 'body' => '<p>s</p>', 'status' => 'scheduled',
            'locale' => 'en', 'store_id' => 'default', 'channel_id' => 'default', 'currency' => 'VND',
            'scheduled_at' => '2026-01-01 00:00:00',
        ]);
        self::assertSame(1, $this->cms->publishScheduled('2026-12-01 00:00:00'));
        $preview = $this->cms->preview((string) $scheduled['id'], $this->scope);
        self::assertSame('published', $preview['status'] ?? null);
    }
}
