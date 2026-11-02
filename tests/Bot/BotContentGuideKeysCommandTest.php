<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Entity\BotMessageTemplate;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bot-content commands know the Mini App guide texts (G-2): "missing" checks their shipped defaults (no row needed),
 * "list" shows each with Default / Customized per language, "seed" (run on every deploy) never creates or overwrites
 * them, so a customized text stays and an untouched one follows the shipped default.
 */
final class BotContentGuideKeysCommandTest extends IntegrationTestCase
{
    public function testMissingChecksTheGuideTextsWithoutRequiringRows(): void
    {
        foreach (['fa', 'en'] as $locale) {
            $tester = $this->command('app:bot-content:missing');
            $tester->execute(['--locale' => $locale]);
            $display = $tester->getDisplay();
            self::assertStringNotContainsString('guide translation', $display, $locale);
            self::assertStringNotContainsString('guide.', $display, 'no guide key reported missing without a row');
            self::assertStringContainsString('Mini App guide texts: 24', $display);
        }
    }

    public function testListShowsEveryGuideTextAndItsStatus(): void
    {
        $this->guideRow('guide.faq.body', 'en', 'Mine', true);
        $this->guideRow('guide.faq.title', 'fa', 'من', false);
        $tester = $this->command('app:bot-content:list');
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Mini App guide texts', $display);
        self::assertSame(24, preg_match_all('/^\s*guide\.[a-z_]+\.(title|body)\s/m', $display));
        self::assertMatchesRegularExpression('/guide\.faq\.body\s+default\s+customized\s/', $display);
        self::assertMatchesRegularExpression('/guide\.faq\.title\s+customized \(inactive\)\s+default\s/', $display);
        self::assertMatchesRegularExpression('/guide\.start\.body\s+default\s+default\s/', $display);
    }

    /** @var array<string, int> the highest id of each table the seed writes: tearDown removes what it created (not purged) */
    private array $seedMarks = [];

    protected function tearDown(): void
    {
        foreach ($this->seedMarks as $table => $max) {
            $this->em->getConnection()->executeStatement('DELETE FROM '.$table.' WHERE id > ?', [$max]);
        }
        parent::tearDown();
    }

    public function testSeedLeavesTheGuideTextsAlone(): void
    {
        $mine = $this->guideRow('guide.faq.body', 'en', 'Mine', true);
        foreach (['bot_message_template', 'bot_button_label', 'setting'] as $table) {
            $this->seedMarks[$table] = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM '.$table);
        }
        $this->seedMarks['bot_message_template'] = max($this->seedMarks['bot_message_template'], (int) $mine->getId());
        $before = (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM bot_message_template WHERE template_key NOT LIKE 'guide.%' AND locale = 'en'");
        // --force rewrites the seeded rows: only when the test database has none of its own to lose.
        foreach (0 === $before ? [[], ['--force' => true]] : [[]] as $options) {
            $tester = $this->command('app:bot-content:seed');
            $tester->execute(['--locale' => 'en'] + $options);
            self::assertSame(0, $tester->getStatusCode());
            self::assertStringContainsString('guide texts (guide.*) are not seeded', $tester->getDisplay());
        }

        $this->em->clear();
        $rows = $this->em->getRepository(BotMessageTemplate::class)->createQueryBuilder('t')->where('t.key LIKE :g')->setParameter('g', 'guide.%')->getQuery()->getResult();
        self::assertCount(1, $rows, 'no guide row created');
        self::assertSame('Mine', $this->em->getRepository(BotMessageTemplate::class)->find($mine->getId())?->getBody(), 'not overwritten, also with --force');
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find($name));
    }

    private function guideRow(string $key, string $locale, string $body, bool $active): BotMessageTemplate
    {
        $row = (new BotMessageTemplate())->setKey($key)->setLocale($locale)->setTitle($key)->setBody($body)->setParseMode('guide')
            ->setCategory('guide')->setIsActive($active)->setIsSystem(false);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }
}
