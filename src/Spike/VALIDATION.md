# PRE-3469 — Validation des contrats UPC contre Sylius

Spike de preuve : implémentation squelette des 4 contrats à risque (`IOrderStateMutator`,
`ITokenCache`, `IConfigurationRepository`, `IPaymentRepository`) contre les vraies APIs Sylius
(`sylius/sylius` ^2.0, PHP 8.2), pour valider qu'ils sont satisfaisables avant de les figer.
Code de preuve — voir `src/Spike/` — mais couvert par 3 niveaux de tests réels (43 tests) :

- **Unitaire** (`tests/PHPUnit/Spike/*Test.php`, hors `SpikeIntegrationTest`) : mocks natifs
  PHPUnit sur chaque collaborateur (StateMachineInterface, CacheItemPoolInterface,
  GatewayConfigInterface, EntityManagerInterface). 38 tests.
- **Intégration réelle** (`SpikeIntegrationTest.php`) : kernel Sylius réellement booté
  (`sylius/test-application`), vraie base MariaDB jetable, vrai state machine Symfony Workflow,
  vrai pool de cache PSR-6, vraie persistance Doctrine — aucun mock. 5 tests.
- Les deux suites tournent avec `make`-style commandes classiques (`composer install`,
  `vendor/bin/phpunit`) une fois la base de test créée — voir section Câblage.

**Verdict global : aucune friction bloquante.** Les 4 contrats sont implémentables contre les
APIs Sylius actuelles, et le prouvent en s'exécutant réellement. Un bug réel a été trouvé et
corrigé (voir IOrderStateMutator), plus trois notes pour la suite (aucune ne remet en cause la
forme des interfaces).

## IOrderStateMutator — squelette : `SyliusOrderStateMutator.php`

Mapping validé : `PaymentOutcome::PAID/AUTHORIZED/CAPTURE_REQUIRED/REFUNDED/FAILED` →
`PaymentTransitions::TRANSITION_COMPLETE/AUTHORIZE/PROCESS/REFUND/FAIL` sur le graphe
`sylius_payment`, via `StateMachineInterface::can()/apply()` — exactement le pattern déjà utilisé
par `PaymentStateResolver::applyTransition()` dans le plugin actuel.

`THREE_DS_PENDING` confirmé sans transition : l'implémentation retourne immédiatement sans
toucher au state machine, la commande reste `new` jusqu'au webhook — conforme au résultat attendu
du ticket.

**Bug réel trouvé par le test d'intégration (corrigé)** : la première version du squelette
appelait `$stateMachine->apply($payment, ...)` sans jamais flush l'EntityManager. Le state
machine (`marking_store: type: method`) ne fait que muter la propriété `state` en mémoire — rien
n'est persisté sans un `flush()` explicite, exactement comme le fait déjà
`PaymentStateResolver::resolve()` dans le plugin actuel (`$this->paymentEntityManager->flush();`
en fin de méthode). Le test unitaire avec mocks ne pouvait pas voir ce bug (il vérifie l'appel à
`apply()`, pas la persistance réelle) — seul le test d'intégration contre une vraie base l'a
révélé : la transition semblait réussir (`can()` → true, `apply()` appelé) mais la commande
restait `new` en base. `IOrderStateMutator` prend maintenant un `EntityManagerInterface` en plus
et flush après chaque transition appliquée.

**Note (non bloquante)** : le contrat prend un `orderId`, mais la transition Symfony Workflow vit
sur le sous-objet `Payment` de la commande (`Order::getLastPayment()`), pas sur l'`Order`
lui-même. Un hop supplémentaire Order → Payment est donc nécessaire côté adaptateur Sylius — ce
que WooCommerce n'aurait pas besoin de faire. C'est le bon endroit pour cette différence (dans
l'adaptateur, pas dans le contrat CMS-agnostique).

## ITokenCache — squelette : `SyliusTokenCache.php`

Aucune friction. `get`/`set`/`delete` se posent 1:1 sur `Psr\Cache\CacheItemPoolInterface::getItem/
save/deleteItem`, exactement comme le docblock de l'interface le prévoyait déjà. Le pool par
défaut de Sylius (`cache.app`, `Symfony\Component\Cache\Adapter\AdapterInterface`) satisfait déjà
`CacheItemPoolInterface` — filesystem, APCu ou Redis selon le déploiement, sans changement de code
côté `SyliusTokenCache`.

## IConfigurationRepository — squelette : `SyliusConfigurationRepository.php`

**Note (non bloquante)** : `GatewayConfigInterface::getConfig()` scope les credentials par
`PaymentMethod` *et* par mode live/test (`config['live_client']` vs `config['test_client']`,
sélectionné par `config['live']` — même pattern que `PayPlugApiClientFactory::getTokenForGatewayConfig()`
existant). Une instance de `SyliusConfigurationRepository` doit donc être construite par
`GatewayConfigInterface` (donc par `PaymentMethod`), pas partagée comme service unique — c'est une
factory, pas un singleton. Ne remet pas en cause le contrat, mais à garder en tête si le futur
client Unified API suppose "un repository = un marchand".

**Point positif** : Sylius expose déjà un `GatewayConfigEncrypter` (expérimental) qui chiffre au
repos l'intégralité du tableau `getConfig()` — si branché, `CLIENT_SECRET` en bénéficie
gratuitement. Ce que `SyliusConfigurationRepository` doit garantir lui-même, c'est qu'un secret
déchiffré ne fuite jamais dans un message de log ou d'exception : `requireString()` n'interpole
jamais que le nom de la clé, jamais sa valeur.

## IPaymentRepository — squelettes : `SyliusPaymentRepository.php` + `Entity/PayplugOperation.php`

**Constat principal du ticket, confirmé** : le plugin actuel ne stocke pas `OperationData` de
façon normalisée. L'id Payplug est aujourd'hui écrit dans le JSON de `Payment::details` et
retrouvé via `LIKE '%id%'` (`PaymentRepository::findOneByPayPlugPaymentId`). Ça fonctionne pour ce
seul lookup, mais ne peut pas porter `markTreated()`/`isTreated()` (il faut un flag d'idempotence
indexé) ni `getByOperationId()` proprement (il faut une colonne indexée, pas une recherche de
sous-chaîne dans un blob sérialisé).

Le squelette introduit donc une nouvelle table `payplug_operation`, sans aucune dépendance au SDK
`payplug/payplug-php` — juste `OperationData` en entrée/sortie. C'est un vrai changement de schéma
(nouvelle table), pas une extension de l'existant — à traiter comme tel dans le chiffrage du futur
ticket de production.

**Note annexe (mécanique, pas conceptuelle)** : `PayplugOperation` vit hors de `src/Entity/` et
n'est pas un `sylius_resource` dans `config/resources.yaml` (contrairement à `Card` et
`RefundHistory`) — enregistrer un `sylius_resource` a été essayé pour le test d'intégration mais
échoue tant que la classe n'implémente pas `Sylius\Resource\Model\ResourceInterface` (grilles/
formulaires/routes Sylius, inutiles pour cette entité de pure persistance). Le mapping Doctrine
réel utilisé pour le test d'intégration passe donc par un `doctrine.orm.mappings` prepend dans
`PayPlugSyliusPayPlugExtension::prependSpikeDoctrineMapping()` — méthode explicitement marquée
PRE-3469-only, restreinte à `kernel.environment === 'test'` (jamais en prod), à retirer avec
`src/Spike/` si le spike est abandonné.

**Bug de portabilité trouvé et corrigé (deux fois)** : la table `payplug_operation` a d'abord été
créée à la main sur la base locale — ça marchait chez moi, mais ni pour un collègue qui pull, ni
en CI. Première tentative de fix : la faire créer par le test lui-même via
`SchemaTool::updateSchema([$metadata], true)` — **destructeur** : avec une liste de classes
partielle, `updateSchema()` calcule un schéma cible ne contenant que cette entité et supprime
toutes les autres tables qu'il ne reconnaît pas dans ce diff, y compris tout le schéma Sylius.
Vérifié en le déclenchant sur la base jetable : il n'est resté que 2 tables sur la centaine
attendue. Aucun dégât réel (base locale jetable), mais invendable en l'état.

Deuxième problème, plus profond : enregistrer le mapping Doctrine dès que
`kernel.environment === 'test'` casse `sylius:fixtures:load` sur une base neuve, *même sans
jamais toucher au spike* — n'importe quelle commande qui boote le kernel en env `test` (donc
l'installation standard du `test-application`) échoue avec « table `payplug_operation`
n'existe pas », puisque Doctrine connaît l'entité sans que la table existe encore.

**Fix final** : une vraie migration Doctrine (`migrations/Version20260720100000.php`), comme
toutes les autres tables du plugin. Contre-intuitif pour une entité de spike, mais c'est la seule
option qui ne casse rien pour qui que ce soit sur un premier `make install` — vérifié en
reconstruisant la base de zéro (`doctrine:database:create` → `doctrine:migration:migrate` →
`sylius:fixtures:load` → suite de tests) et en rejouant le test d'intégration 3 fois de suite.
`up()`/`down()` sont chacun protégés par `$this->skipIf('test' !== APP_ENV, ...)` : une migration
de plugin normale s'applique dans tous les environnements, y compris en prod chez un marchand —
sans ce garde-fou, chaque installation du plugin en production créerait une table `payplug_operation`
qui ne sert jamais à rien en dehors des tests de ce spike.

## Câblage (pour rejouer les tests)

- `composer.json` : `repositories` avec un repository `vcs` vers
  `https://github.com/payplug/unified-plugin-core.git` + require-dev
  `payplug/unified-plugin-core: "dev-develop"`, pour que les squelettes implémentent réellement
  les interfaces (et non des copies locales) — fonctionne pour n'importe qui ayant accès au repo
  GitHub (même accès que pour ce repo), et en CI via l'étape `Composer - Github Auth` déjà
  configurée dans `payplug/template-ci`. Une première version utilisait un repository `path`
  local (`../../unified-plugin-core`) : ça ne marche que sur une machine avec les deux repos
  clonés côte à côte, cassait `composer install` pour tout le monde d'autre — corrigé.
  Nécessite que le pin exact `symfony/polyfill-mbstring: 1.28.0` d'UPC ait été relâché en
  `^1.28` (corrigé dans unified-plugin-core suite à ce spike — il bloquait tout
  `composer install` aux côtés de `sylius/sylius ^2.0`, qui exige `^1.31`).
  Contrepartie connue : ça ajoute une résolution réseau vers GitHub à chaque install (léger coût
  CI/dev) — pas d'alternative disponible ici (pas de Packagist privé configuré dans cet org pour
  `payplug/unified-plugin-core`, et un repository `path` est exclu pour la raison ci-dessus).
  Ce compromis disparaît quand PRE-3563 posera la vraie dépendance de production.
- `phpunit.xml.dist` : `KERNEL_CLASS_PATH` renommé en `KERNEL_CLASS` — la variable que Symfony lit
  réellement pour `KernelTestCase::bootKernel()`. Sans ce fix, aucun test à base de kernel
  (fonctionnel/intégration) n'a jamais pu tourner dans ce repo — un bug de config préexistant,
  invisible tant qu'aucun test de ce type n'existait.
- `tests/TestApplication/.env.test.local` (gitignored) : `DATABASE_URL` pointée vers un conteneur
  MariaDB jetable (`docker run --name payplug-pre3469-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p
  3309:3306 mariadb:latest`) — le MySQL système de la machine refuse `root@127.0.0.1` sans mot de
  passe. `.env.local` seul ne suffit pas : Symfony l'ignore délibérément quand `APP_ENV=test`
  (reproductibilité des tests) — il faut `.env.test.local` spécifiquement.
- Base créée/migrée avec les commandes standard du Makefile (`doctrine:database:create`,
  `doctrine:migration:migrate`, `sylius:payment:generate-key`, `sylius:fixtures:load`) — la table
  `payplug_operation` est créée automatiquement par `migrations/Version20260720100000.php`, plus
  besoin d'aucune étape manuelle (voir plus bas pourquoi une vraie migration a été nécessaire).

Ce câblage (composer + config) est à revoir quand PRE-3563 (OAuth réel contre UPC) posera la
vraie dépendance de production ; le `.env.test.local` et le conteneur Docker restent locaux à la
machine de dev et n'ont pas vocation à être commités.

**Isolation des tests** : `SpikeIntegrationTest` ne tourne dans aucune transaction annulée en fin
de test (pas de `DAMADoctrineTestBundle`). Une première version cherchait un paiement de fixture
déjà à l'état `new` — ça fonctionne une fois, mais une commande réelle ne repasse jamais à `new`
une fois transitionnée, donc ce pool fini s'épuise au fil des ré-exécutions contre la même base
jetable. Corrigé en créant un `Payment` frais sur une commande de fixture existante à chaque test
(`createOrderWithFreshPayment()`) plutôt que d'en chercher un dans un état donné — vérifié stable
sur 3 exécutions consécutives. Une vraie suite (non-spike) voudrait un rollback transactionnel
entre tests plutôt que ce contournement.
