<?php

declare(strict_types=1);

namespace App\Tests\Reseller\MiniApp;

use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MissingTranslationRecorder;
use App\Tests\Support\ResellerMiniAppFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * The reseller section is translated completely: Persian and English have the same keys and placeholders, and every
 * reseller screen renders in both without a missing translation.
 */
final class ResellerMiniAppTranslationsTest extends IntegrationTestCase
{
    use ResellerMiniAppFixtures;

    public function testPersianAndEnglishHaveTheSameKeysAndPlaceholders(): void
    {
        $dir = dirname(__DIR__, 3).'/translations/';
        $fa = array_filter(Yaml::parseFile($dir.'miniapp.fa.yaml'), static fn (string $k): bool => str_starts_with($k, 'reseller.') || 'nav.reseller' === $k, ARRAY_FILTER_USE_KEY);
        $en = array_filter(Yaml::parseFile($dir.'miniapp.en.yaml'), static fn (string $k): bool => str_starts_with($k, 'reseller.') || 'nav.reseller' === $k, ARRAY_FILTER_USE_KEY);
        self::assertNotSame([], $fa);
        self::assertSame(array_keys($fa), array_keys($en));
        foreach ($fa as $key => $text) {
            preg_match_all('/%[a-z_]+%/', (string) $text, $faPlaceholders);
            preg_match_all('/%[a-z_]+%/', (string) $en[$key], $enPlaceholders);
            sort($faPlaceholders[0]);
            sort($enPlaceholders[0]);
            self::assertSame($faPlaceholders[0], $enPlaceholders[0], $key);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'fa' => ['fa'];
        yield 'en' => ['en'];
    }

    #[DataProvider('locales')]
    public function testEveryScreenRendersWithoutAMissingTranslation(string $locale): void
    {
        $this->setUpResellerMiniApp();
        try {
            $session = $this->authenticate(self::CUSTOMER);
            $label = $this->appPostJson('/app/api/reseller/customers', $session, ['name' => 'Ali', 'phone' => '0912345', 'note' => 'n'], self::newKey());
            $this->claimWith($this->makeLink()->token, '8701');
            $this->buy();
            $this->claimLinks()->createForReseller($this->user, (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM vpn_service WHERE sold_by_reseller_id = ?', [$this->reseller->getId()]));
            $recorder = self::getContainer()->get(MissingTranslationRecorder::class);
            $recorder->reset();
            $lang = 'en' === $locale ? '?lang=en' : '';
            $pages = [
                '/app/view/reseller', '/app/view/reseller/new', '/app/view/reseller/new/'.$this->plan->getId(), '/app/view/reseller/services',
                '/app/view/reseller/services?tab=claimed', '/app/view/reseller/services/'.$this->sold->getId(), '/app/view/reseller/services/'.$this->sold->getId().'/renew',
                '/app/view/reseller/customers', '/app/view/reseller/customers/new', (string) $label['redirect'], '/app/view/reseller/links',
            ];
            foreach ($pages as $page) {
                $this->appGet($page.(str_contains($page, '?') && '' !== $lang ? '&lang=en' : $lang), $session);
                self::assertSame(200, $this->httpStatus(), $page);
            }
            $mine = (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM vpn_service WHERE user_id = ? AND sold_by_reseller_id IS NOT NULL', [$this->user->getId()]);
            $this->appGet('/app/view/reseller/services/'.$mine.$lang, $session);
            $this->appPostJson('/app/api/reseller/new'.$lang, $session, ['planId' => (string) $this->plan->getId()], self::newKey());
            $this->appPostJson('/app/api/reseller/services/'.$mine.'/claim-link'.$lang, $session);

            self::assertSame([], $recorder->missing());
        } finally {
            $this->tearDownClaims();
        }
    }
}
