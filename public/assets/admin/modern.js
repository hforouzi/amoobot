// Modern admin layout (docs/design/admin-redesign.md, 5.3): Stimulus, vendored as one ES module file (no build step,
// no Node in deploy), and the shell's controllers. Loaded only by templates/admin/modern/layout.html.twig.
import { Application } from './vendor/stimulus.js';
import ThemeController from './controllers/theme_controller.js';
import CollapseController from './controllers/collapse_controller.js';

const application = Application.start();
application.register('theme', ThemeController);
application.register('collapse', CollapseController);
