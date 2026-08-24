<?php

namespace Tests\Unit;

use App\Support\CloudDisk;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CloudDiskTest extends TestCase
{
    #[Test]
    public function it_resolves_nothing_off_laravel_cloud(): void
    {
        $config = CloudDisk::s3(null, null);

        $this->assertNull($config['bucket']);
        $this->assertNull($config['key']);
        $this->assertNull($config['endpoint']);

        // The one value with a default: the S3 adapter needs a region, and R2
        // does not use a meaningful one.
        $this->assertSame('auto', $config['region']);
        $this->assertFalse($config['use_path_style_endpoint']);
    }

    #[Test]
    public function it_reads_the_only_attached_bucket(): void
    {
        $config = CloudDisk::s3($this->encode([$this->bucket('public', 'fls-one')]), null);

        $this->assertSame('fls-one', $config['bucket']);
        $this->assertSame('key-public', $config['key']);
        $this->assertSame('secret-public', $config['secret']);
        $this->assertSame('https://r2.example.com', $config['endpoint']);
        $this->assertSame('auto', $config['region']);
    }

    #[Test]
    public function an_explicit_value_beats_the_attached_bucket(): void
    {
        $config = CloudDisk::s3(
            $this->encode([$this->bucket('public', 'fls-one')]),
            null,
            ['bucket' => 'chosen-by-hand', 'region' => 'ap-southeast-1'],
        );

        $this->assertSame('chosen-by-hand', $config['bucket']);
        $this->assertSame('ap-southeast-1', $config['region']);

        // Everything it did not speak for still comes from the bucket.
        $this->assertSame('key-public', $config['key']);
    }

    #[Test]
    public function a_blank_value_does_not_shadow_the_attached_bucket(): void
    {
        // .env.example ships AWS_BUCKET= and AWS_ACCESS_KEY_ID= as empty
        // placeholders, and env() returns '' for those, not null. Treating ''
        // as a real answer would report no bucket on a host that has one.
        $config = CloudDisk::s3(
            $this->encode([$this->bucket('public', 'fls-one')]),
            null,
            ['bucket' => '', 'key' => '', 'use_path_style_endpoint' => ''],
        );

        $this->assertSame('fls-one', $config['bucket']);
        $this->assertSame('key-public', $config['key']);
        $this->assertFalse($config['use_path_style_endpoint']);
    }

    #[Test]
    public function it_selects_the_named_bucket_when_several_are_attached(): void
    {
        $json = $this->encode([
            $this->bucket('public', 'fls-public'),
            $this->bucket('private', 'fls-private'),
        ]);

        $this->assertSame('fls-private', CloudDisk::s3($json, 'private')['bucket']);
        $this->assertSame('key-private', CloudDisk::s3($json, 'private')['key']);
        $this->assertSame('fls-public', CloudDisk::s3($json, 'public')['bucket']);
    }

    #[Test]
    public function it_refuses_to_guess_between_several_attached_buckets(): void
    {
        $json = $this->encode([
            $this->bucket('public', 'fls-public'),
            $this->bucket('private', 'fls-private'),
        ]);

        // The whole point: picking one here could put policy-gated documents in
        // the bucket Cloud serves over a public URL. A null bucket fails loudly
        // in cicto:host-check instead.
        $this->assertNull(CloudDisk::s3($json, null)['bucket']);
    }

    #[Test]
    public function naming_a_bucket_that_is_not_attached_resolves_nothing(): void
    {
        $json = $this->encode([$this->bucket('public', 'fls-public')]);

        $this->assertNull(CloudDisk::s3($json, 'private')['bucket']);
    }

    #[Test]
    public function malformed_configuration_is_treated_as_no_buckets(): void
    {
        // Throwing here would happen while the configuration is being built,
        // taking artisan down with it -- including the host-check that would
        // have explained the problem.
        foreach (['', '   ', 'not json', '{"disk":', 'null', '"a string"', '[1, 2]'] as $raw) {
            $this->assertNull(CloudDisk::s3($raw, null)['bucket'], "Failed on: {$raw}");
        }
    }

    /** @param  list<array<string, mixed>>  $entries */
    private function encode(array $entries): string
    {
        return (string) json_encode($entries);
    }

    /** @return array<string, mixed> */
    private function bucket(string $disk, string $bucket): array
    {
        return [
            'disk' => $disk,
            'is_default' => $disk === 'public',
            'access_key_id' => "key-{$disk}",
            'access_key_secret' => "secret-{$disk}",
            'bucket' => $bucket,
            'default_region' => 'auto',
            'endpoint' => 'https://r2.example.com',
            'use_path_style_endpoint' => false,
        ];
    }
}
