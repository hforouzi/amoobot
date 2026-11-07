<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\UI\DashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

/**
 * An action added to the detail page only is reached only through a Show action on the list: without one it has no
 * link anywhere (the services list after PM12, the plans list before 99f1a27). Built as EasyAdmin builds it
 * (ActionFactory: the dashboard's actions, then the CRUD's).
 */
final class AdminCrudActionsReachabilityTest extends KernelTestCase
{
    private const BUILT_IN = [Action::NEW, Action::EDIT, Action::DELETE, Action::DETAIL, Action::INDEX, Action::BATCH_DELETE, Action::SAVE_AND_ADD_ANOTHER, Action::SAVE_AND_CONTINUE, Action::SAVE_AND_RETURN];

    public function testNoCustomActionIsOnTheDetailPageOnlyWhileTheListHasNoShowAction(): void
    {
        self::bootKernel();
        $dashboard = self::getContainer()->get(DashboardController::class);
        $controllers = self::crudControllers();
        self::assertGreaterThan(20, count($controllers));

        $unreachable = [];
        foreach ($controllers as $fqcn) {
            $controller = self::getContainer()->get($fqcn);
            self::assertInstanceOf(AbstractCrudController::class, $controller);
            $config = $controller->configureActions($dashboard->configureActions())->getAsDto(null);
            $actions = $config->getActions();
            self::assertIsArray($actions);

            $onIndex = array_keys($actions[Crud::PAGE_INDEX] ?? []);
            $detailOnly = array_diff(array_keys($actions[Crud::PAGE_DETAIL] ?? []), $onIndex, self::BUILT_IN);
            $showOnIndex = in_array(Action::DETAIL, $onIndex, true) && !in_array(Action::DETAIL, $config->getDisabledActions(), true);
            if ([] !== $detailOnly && !$showOnIndex) {
                $unreachable[] = sprintf('%s: %s', $fqcn, implode(', ', $detailOnly));
            }
        }

        self::assertSame([], $unreachable, 'add ->add(Crud::PAGE_INDEX, Action::DETAIL)');
    }

    /** @return list<class-string<AbstractCrudController>> */
    private static function crudControllers(): array
    {
        $controllers = [];
        foreach ((new Finder())->files()->in(dirname(__DIR__, 2).'/src/Admin')->name('*CrudController.php') as $file) {
            $fqcn = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(dirname(__DIR__, 2).'/src/')));
            if (is_subclass_of($fqcn, AbstractCrudController::class) && !(new \ReflectionClass($fqcn))->isAbstract()) {
                $controllers[] = $fqcn;
            }
        }
        sort($controllers);

        return $controllers;
    }
}
