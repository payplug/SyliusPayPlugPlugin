# PRE-3469 — Validation des contrats UPC contre Sylius

`IOrderStateMutator`, `ITokenCache` et `IConfigurationRepository` ont été promus en implémentations
réelles, branchées dans le code de production existant (voir `src/PaymentProcessing/PayplugOrderStateMutator.php`,
`src/TokenCache/PayplugTokenCache.php`, `src/ConfigurationRepository/PayplugConfigurationRepository.php`).
`IPaymentRepository` reste hors scope de ce ticket (lié à un Value Object fortement couplé à
l'API Unifiée, non encore branché sur le plugin) — son squelette de preuve reste dans ce dossier,
voir `SyliusPaymentRepository.php`.

Couverture de tests, 3 niveaux :

- **Unitaire** (`tests/PHPUnit/{PaymentProcessing,TokenCache,ConfigurationRepository}/*Test.php`
  + `tests/PHPUnit/Command/Handler/NotifyPaymentRequestHandlerTest.php`) : mocks natifs PHPUnit
  sur chaque collaborateur.
- **Intégration réelle** (`SpikeIntegrationTest.php`, toujours dans ce dossier car c'est le seul
  endroit du plugin qui boote un vrai kernel Sylius pour les tests) : kernel Sylius réellement
  booté, vraie base MariaDB jetable, vrai state machine Symfony Workflow, vrai pool de cache
  PSR-6, vraie persistance Doctrine — aucun mock. Couvre les 4 contrats, y compris
  `IPaymentRepository`.
- Les deux suites tournent avec `composer install`, `vendor/bin/phpunit` une fois la base de
  test créée — voir section Câblage.

**Verdict global : aucune friction bloquante restante.** Une friction bloquante a été trouvée et
corrigée pendant ce rework (voir « Friction bloquante corrigée » ci-dessous), plus deux notes non
bloquantes (aucune ne remet en cause la forme des interfaces). Voir aussi « Validation finale »
plus bas pour la passe de régression complète (test fonctionnel réel, `SpikeIntegrationTest`,
constat sur Behat).

## Friction bloquante corrigée : `payplug/unified-plugin-core` en `require-dev`

`SyliusOrderStateMutator`/`SyliusTokenCache`/`SyliusConfigurationRepository` (les squelettes
d'origine) implémentaient directement les interfaces UPC depuis `src/Spike/`, avec
`payplug/unified-plugin-core` en `require-dev` uniquement. Tant que ce code restait isolé et
jamais référencé par une classe de production, ça ne posait pas de problème. Mais brancher
`PayplugOrderStateMutator` dans `NotifyPaymentRequestHandler` — une classe systématiquement
chargée, y compris en production — change la donne : un vrai `composer install --no-dev` chez un
marchand n'installerait pas `unified-plugin-core`, et charger une classe qui `implements` une
interface inexistante est une erreur PHP fatale, pas un échec silencieux.

**Fix** : `payplug/unified-plugin-core` est passé en dépendance `require` (pinné sur `dev-master`,
même repository VCS — voir « Câblage » ci-dessous pour le changement de branche). Documenté ici
plutôt que traité comme un simple détail de
`composer.json`, parce que ça anticipe une partie de ce que PRE-3563 (la vraie dépendance de
production pour l'OAuth) devait poser — voir section Câblage plus bas pour ce qui reste à faire
pour PRE-3563.

## IOrderStateMutator — réel : `PayplugOrderStateMutator.php`

Mapping validé : `PaymentOutcome::PAID/AUTHORIZED/CAPTURE_REQUIRED/REFUNDED/FAILED` →
`PaymentTransitions::TRANSITION_COMPLETE/AUTHORIZE/PROCESS/REFUND/FAIL` sur le graphe
`sylius_payment`, via `StateMachineInterface::can()/apply()` — exactement le pattern déjà utilisé
par `PaymentStateResolver::applyTransition()` dans le plugin actuel.

`THREE_DS_PENDING` confirmé sans transition : l'implémentation retourne immédiatement sans
toucher au state machine, la commande reste `new` jusqu'au webhook — conforme au résultat attendu
du ticket.

**Branché en production** : appel additif dans `NotifyPaymentRequestHandler`, juste après le
`PaymentTransitionApplier::apply($payment)` existant, une fois la transition déjà appliquée par
ce dernier. Le statut PayPlug déjà connu à cet endroit (`$payment->getDetails()['status']`) est
traduit en `PaymentOutcome` (seuls `STATUS_CAPTURED`/`STATUS_AUTHORIZED`/`FAILED` ont un
équivalent — `STATUS_ABORTED`/`STATUS_CANCELED*`, mappés par `PaymentTransitionApplier` sur
`TRANSITION_CANCEL`, n'ont pas d'équivalent `PaymentOutcome` et sont donc volontairement ignorés,
pas forcés). L'appel est protégé par un `try/catch` qui journalise sans jamais propager : la
transition ayant déjà été appliquée par `PaymentTransitionApplier`, le garde-fou `can()` du
mutateur en fait un no-op silencieux dans le cas normal ; ce câblage prouve que le contrat
fonctionne contre un webhook réel sans remplacer ni risquer le flux existant.

**Note (non bloquante)** : le contrat prend un `orderId`, mais la transition Symfony Workflow vit
sur le sous-objet `Payment` de la commande (`Order::getLastPayment()`), pas sur l'`Order`
lui-même. Un hop supplémentaire Order → Payment est donc nécessaire côté adaptateur Sylius — ce
que WooCommerce n'aurait pas besoin de faire. C'est le bon endroit pour cette différence (dans
l'adaptateur, pas dans le contrat CMS-agnostique).

**Note annexe (mécanique, pas conceptuelle)** : le plugin a aujourd'hui deux chemins de
production distincts qui appliquent des transitions Symfony Workflow sur un paiement —
`PaymentTransitionApplier` (webhooks/status, celui utilisé ci-dessus) et `PaymentStateResolver`
(réconciliation CLI, `payplug:update-payment-state`). Ce ticket ne les unifie pas ; ça reste deux
implémentations parallèles du même idiome `can()`/`apply()`.

## ITokenCache — réel : `PayplugTokenCache.php`

Aucune friction. `get`/`set`/`delete` se posent 1:1 sur `Psr\Cache\CacheItemPoolInterface::getItem/
save/deleteItem`, exactement comme le docblock de l'interface le prévoyait déjà. Le pool par
défaut de Sylius (`cache.app`, `Symfony\Component\Cache\Adapter\AdapterInterface`) satisfait déjà
`CacheItemPoolInterface`, aliasé nativement par FrameworkBundle — filesystem, APCu ou Redis selon
le déploiement, sans changement de code côté `PayplugTokenCache`.

**Cible confirmée par le ticket** : `ITokenCache` cible le cache du token JWT OAuth
(authentification via le SDK PayPlug), pas le stockage carte/one-click — la sauvegarde de carte
est une entité Doctrine permanente (`Card`), sans aucun cache impliqué.

**Validé par un test d'intégration réel, pas par un appel de production** : le seul analogue de
production existant, `PayPlugApiClientFactory::getTokenForGatewayConfig()`, met en cache le token
OAuth qui conditionne l'authentification de *toutes* les gateways du plugin. Remplacer sa logique
de cache inline aurait un risque de régression disproportionné par rapport à l'objectif de
validation de ce ticket ; `PayplugTokenCache` reste donc validé par un test d'intégration contre
un vrai pool PSR-6 (`SpikeIntegrationTest::testTokenCache_realCachePool_roundTripsThroughRealAdapter`),
sans point d'entrée de production.

## IConfigurationRepository — réel : `PayplugConfigurationRepository.php`

`GatewayConfigInterface::getConfig()` scope les credentials par `PaymentMethod` *et* par mode
live/test (`config['live_client']` vs `config['test_client']`, sélectionné par `config['live']` —
même pattern que `PayPlugApiClientFactory::getTokenForGatewayConfig()` existant). Une instance de
`PayplugConfigurationRepository` doit donc être construite par `GatewayConfigInterface` (donc par
`PaymentMethod`), pas partagée comme service unique — c'est une factory, pas un singleton. Ne
remet pas en cause le contrat, mais à garder en tête si le futur client Unified API suppose "un
repository = un marchand".

**Point positif** : Sylius expose déjà un `GatewayConfigEncrypter` (expérimental) qui chiffre au
repos l'intégralité du tableau `getConfig()` — si branché, `CLIENT_SECRET` en bénéficie
gratuitement. Ce que `PayplugConfigurationRepository` doit garantir lui-même, c'est qu'un secret
déchiffré ne fuite jamais dans un message de log ou d'exception : `requireString()` n'interpole
jamais que le nom de la clé, jamais sa valeur.

**Friction non bloquante, résolue par un choix explicite** : `getPublicKeyId()`/
`getPublicKeyValue()` n'ont aucun équivalent de production — le plugin n'a aucune notion de clé
publique Hosted Fields aujourd'hui (`grep` sur `public_key`/`PUBLIC_KEY` dans `src/` ne retourne
rien en dehors de ce fichier). Plutôt que de lever une exception comme `getClientId()`/
`getClientSecret()`, ces deux méthodes renvoient une chaîne vide tant qu'aucun code de production
n'écrit ces clés — le contrat reste implémentable de bout en bout, prêt pour quand Hosted Fields
sera construit.

## IPaymentRepository — hors scope, squelette inchangé : `SyliusPaymentRepository.php` + `Entity/PayplugOperation.php`

Hors scope de ce ticket (lié à un Value Object fortement couplé à l'API Unifiée, non encore
branché sur le plugin) — reste un squelette de preuve, inchangé depuis la version précédente de
ce document.

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

## Validation finale (2026-07-27) : passe de régression complète

En complément des suites automatisées ci-dessus :

- **Test fonctionnel réel**, environnement `plugin-dockerized-sylius` branché sur la QA PayPlug
  (`api-qa.payplug.com`) : parcours d'achat complet avec un moyen de paiement carte PayPlug
  configuré via l'OAuth réel, commande `000000022`. Le webhook IPN réel a été reçu et traité par
  `NotifyPaymentRequestHandler` (`payment_id: pay_2iZJiB7mxAoc3poeZTTmeP`, `status: captured`),
  paiement transitionné en `completed`, **aucun warning** de l'appel additif à
  `PayplugOrderStateMutator` dans les logs, aucune régression sur le flux existant. Seule la
  branche `STATUS_CAPTURED → PaymentOutcome::PAID` a été exercée en conditions réelles ; les autres
  branches (`THREE_DS_PENDING`, `AUTHORIZED`, `CAPTURE_REQUIRED`, `REFUNDED`, `FAILED`) et le garde
  multi-paiements (commit `779530d`) restent couverts uniquement par les tests unitaires.
- **`SpikeIntegrationTest`** rejoué contre une vraie base MariaDB jetable : `OK (5 tests, 17
  assertions)`.
- **Behat** : suite non exécutable en l'état — voir ci-dessous, sans lien avec ce ticket.

### Behat : suite cassée, non liée à ce ticket

`vendor/bin/behat --dry-run` échoue immédiatement :

```
`Lakion\Behat\MinkDebugExtension` extension file or class could not be located.
```

En creusant :

- `behat.yml.dist` référence `Tests\PayPlug\SyliusPayPlugPlugin\Application\Kernel` et
  `tests/Application/config/bootstrap.php` — mais `tests/Application/` **n'existe pas** dans ce
  repo (seul `tests/TestApplication/` existe, utilisé par PHPUnit).
- La clé d'extension `Lakion\Behat\MinkDebugExtension` n'est pas le FQCN réel fourni par
  `lakion/mink-debug-extension` ni par `friends-of-behat/mink-debug-extension` (les deux packages
  sont installés, mais sous un namespace différent).
- `git log -- behat.yml.dist` montre que ce fichier n'a pas été modifié depuis le commit initial du
  repo.
- Aucun workflow CI de ce repo n'exécute Behat.

**Conclusion** : cette suite est cassée depuis longtemps, indépendamment de PRE-3469, et ne fait
partie d'aucune porte de qualité active. La remettre en état est un chantier séparé, hors scope de
ce ticket.

## Câblage (pour rejouer les tests)

- `composer.json` : `repositories` avec un repository `vcs` vers
  `https://github.com/payplug/unified-plugin-core.git` + `payplug/unified-plugin-core:
  "dev-master"` désormais en dépendance `require` (voir « Friction bloquante corrigée »
  ci-dessus) — fonctionne pour n'importe qui ayant accès au repo GitHub (même accès que pour ce
  repo), et en CI via l'étape `Composer - Github Auth` déjà configurée dans
  `payplug/template-ci`. Une première version utilisait un repository `path` local
  (`../../unified-plugin-core`) : ça ne marche que sur une machine avec les deux repos clonés
  côte à côte, cassait `composer install` pour tout le monde d'autre — corrigé. Nécessitait que
  le pin exact `symfony/polyfill-mbstring: 1.28.0` d'UPC (en conflit avec le `^1.31` exigé par
  `sylius/sylius ^2.0`) soit relâché — corrigé côté `unified-plugin-core` (`1.30.0 || ^1.31`) et
  fusionné sur `master` le 2026-07-21 ; ce repo pointait initialement sur `dev-develop` en
  attendant la fusion, reposté sur `dev-master` le 2026-07-27 une fois confirmée (`origin/master`
  == `origin/develop`, commit `e6a6733`) — revalidé par un `composer update
  payplug/unified-plugin-core --with-all-dependencies` contre le vrai repo VCS (sans override
  `path`), résolution propre sur `symfony/polyfill-mbstring v1.38.2`, suite complète
  (`phpunit` 170 tests, `phpstan`) toujours au vert derrière. Contrepartie connue : ça ajoute une
  résolution réseau vers GitHub à chaque install (léger coût CI/dev) — pas d'alternative
  disponible ici (pas de Packagist privé configuré dans cet org pour
  `payplug/unified-plugin-core`, et un repository `path` est exclu pour la raison ci-dessus). Ce
  compromis disparaît quand PRE-3563 posera la vraie dépendance de production définitive (OAuth
  réel contre UPC).
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
  besoin d'aucune étape manuelle.

**Isolation des tests** : `SpikeIntegrationTest` ne tourne dans aucune transaction annulée en fin
de test (pas de `DAMADoctrineTestBundle`). Une première version cherchait un paiement de fixture
déjà à l'état `new` — ça fonctionne une fois, mais une commande réelle ne repasse jamais à `new`
une fois transitionnée, donc ce pool fini s'épuise au fil des ré-exécutions contre la même base
jetable. Corrigé en créant un `Payment` frais sur une commande de fixture existante à chaque test
(`createOrderWithFreshPayment()`) plutôt que d'en chercher un dans un état donné — vérifié stable
sur 3 exécutions consécutives. Une vraie suite (non-spike) voudrait un rollback transactionnel
entre tests plutôt que ce contournement.
