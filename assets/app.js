/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

import './bootstrap.js';

// 1. Import du CSS de Bootstrap (grâce à la commande de l'étape 3)
import 'bootstrap/dist/css/bootstrap.min.css';

// 2. Import du JS de Bootstrap
import 'bootstrap';

console.log('Bootstrap est chargé via AssetMapper !');
