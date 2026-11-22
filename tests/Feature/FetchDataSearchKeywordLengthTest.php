<?php

namespace Teksite\Handler\Tests\Feature;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Teksite\Handler\Contracts\FetchDataContract;
use Teksite\Handler\Tests\Fixtures\Models\Post;
use Teksite\Handler\Tests\Fixtures\Models\User;
use Teksite\Handler\Tests\TestCase;

class FetchDataSearchKeywordLengthTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Ada']);
    }

    private function createPost(string $title): void
    {
        Post::create(['user_id' => $this->user->id, 'title' => $title]);
    }

    /**
     * @return int Number of posts whose title matches the given search keyword.
     */
    private function searchTotal(string $keyword): int
    {
        $this->app->instance('request', Request::create('/', 'GET', ['s' => $keyword]));

        return $this->app->make(FetchDataContract::class)
            ->search(['title'])
            ->get(Post::class)
            ->total();
    }

    #[Test]
    public function a_keyword_longer_than_the_configured_length_is_cut(): void
    {
        $this->createPost('abcdefghij');

        config()->set('handler.search_input_field_length', 50);

        // Control: the whole keyword is used, so nothing matches.
        $this->assertSame(0, $this->searchTotal('abcdefXXXX'));

        config()->set('handler.search_input_field_length', 6);

        // Only "abcdef" is used now.
        $this->assertSame(1, $this->searchTotal('abcdefXXXX'));
    }

    #[Test]
    public function the_default_configured_length_is_applied(): void
    {
        $length = (int) config('handler.search_input_field_length');

        $this->assertGreaterThanOrEqual(1, $length);
        $this->assertLessThanOrEqual(1000, $length);

        $this->createPost(str_repeat('a', $length));

        $this->assertSame(1, $this->searchTotal(str_repeat('a', $length + 50)));
    }

    #[Test]
    public function the_length_counts_characters_and_not_bytes(): void
    {
        $this->createPost("\u{E9}\u{E9}\u{E9}\u{E9}\u{E9}abc");

        config()->set('handler.search_input_field_length', 5);

        $this->assertSame(1, $this->searchTotal("\u{E9}\u{E9}\u{E9}\u{E9}\u{E9}zzz"));
    }

    #[Test]
    public function a_length_below_one_is_raised_to_one(): void
    {
        $this->createPost('zebra');
        $this->createPost('apple');

        foreach ([0, -5] as $length) {
            config()->set('handler.search_input_field_length', $length);

            $this->assertSame(1, $this->searchTotal('zzzz'), "Length {$length} must behave like 1.");
        }
    }

    #[Test]
    public function the_length_can_never_exceed_the_hard_cap_of_one_thousand(): void
    {
        $this->createPost(str_repeat('a', 1000));

        config()->set('handler.search_input_field_length', 5000);

        // Without the cap the 1500 characters would not be found inside a 1000 character title.
        $this->assertSame(1, $this->searchTotal(str_repeat('a', 1500)));
    }
}
