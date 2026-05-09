# CLAUDE.md — src/frontend/

> Sous-`CLAUDE.md` chargé automatiquement quand tu édites un fichier dans `src/frontend/`. Pour les règles globales, voir `/CLAUDE.md`.

## Périmètre

UI du module pour les **deux Panels** :
- `src/frontend/pterodactyl/` → Vue 2.7 + Vuex (pour Pterodactyl Panel original)
- `src/frontend/pelican/` → Livewire 3 + Alpine.js (pour Pelican Panel)
- `src/frontend/shared/` → CSS/SCSS, i18n, assets, **strictement pas de logique JS/PHP**

## Quand Vue 2 vs Livewire ?

| Tu travailles sur... | Stack |
|---|---|
| Onglet serveur dans Pterodactyl Panel original | Vue 2.7 (Options API) + Vuex |
| Onglet serveur dans Pelican Panel | Livewire 3 + Alpine.js |
| Page admin Policies dans Pterodactyl | Vue 2.7 (admin SPA legacy) ou Blade + jQuery selon la version |
| Page admin Policies dans Pelican | Livewire 3 |
| Strings i18n, CSS, icônes, illustrations | `shared/` (les deux Panels y puisent) |

**Règle :** un changement de logique métier doit être implémenté DEUX FOIS si l'UI est dans les deux Panels. Pas de raccourci. Le service backend factorise, l'UI est dupliquée par contrainte.

## Conventions Vue 2

- **Options API obligatoire** (Vue 2.7 max — Composition API présente mais on s'aligne avec Pterodactyl qui utilise Options).
- Pas de TypeScript dans `pterodactyl/` (le panel est en JS).
- `data()` retourne un objet, `computed` pour le dérivé, `methods` pour les actions, `watch` pour les effets.
- Vuex pour le state global (catalogue paginé, server context, policies de l'admin).
- Composables → impossible en Options API, factoriser en mixins ou helpers JS purs.
- Tests : Vitest + `@vue/test-utils@1` (compat Vue 2).

## Conventions Livewire

- **Sérialisation** : seuls les types primitifs + Eloquent Model + `Livewire\Wireable` peuvent être propriétés publiques. Pas d'objets PHP arbitraires.
- **Alpine.js** pour les interactions purement front (modales, tooltips, transitions). Pas de fetch dans Alpine — laisse Livewire piloter.
- **Wire methods** suffixe dans le DOM : `wire:click="install"` (pas `install()`).
- **Loading states** : `wire:loading.attr="disabled"` ou `wire:loading.class="opacity-50"`.
- **Validation** : règles dans `protected $rules`, validation lazy avec `$this->validateOnly($propertyName)`.
- Tests : Pest + helpers `livewire/livewire`.

## i18n

- **Toutes** les strings UI passent par i18n. **Aucune** string hardcodée.
- Langues v1 : **FR** + **EN**. Fichiers : `src/shared/i18n/fr.json`, `src/shared/i18n/en.json`.
- Côté Vue : import du JSON via `vue-i18n@8` (compat Vue 2).
- Côté Livewire : Laravel `__()` natif → `resources/lang/<locale>/pmcp.php`.
- Lint check : ajouter un script `scripts/check-i18n.sh` qui grep des patterns suspects (strings entre quotes dans templates Vue/Blade) et fail la CI si trouvé.

## Accessibilité

- Cible : **WCAG 2.2 AA** minimum.
- **Navigation clavier** sur tous les éléments interactifs (test : naviguer le module entier au Tab + Enter).
- **Roles ARIA** corrects : `dialog` pour les modales, `tablist`/`tab`/`tabpanel` pour les onglets, etc.
- **Focus traps** dans les modales (libs : `focus-trap` côté Vue, Alpine `x-trap` côté Livewire).
- Contraste de couleurs : ratio ≥ 4.5:1 sur les textes normaux, ≥ 3:1 sur les gros textes.
- Toujours invoquer `ui-visual-validator` (subagent) après un changement frontend non-trivial.
- Pour les audits poussés, utiliser la skill `scan-and-fix-accessibility` (BrowserStack MCP).

## Quand utiliser `21st-dev/magic` ?

✅ **Oui** :
- Scaffolding initial d'un composant non-trivial (modal d'install, card plugin, table de versions)
- Recherche d'inspiration pour un layout

❌ **Jamais** :
- Pour des composants qui touchent aux APIs internes du panel hôte (Vuex stores Pterodactyl, hooks Pelican)
- Pour la logique métier (compat check, etc.)

Toujours **adapter** le code généré aux conventions ci-dessus avant commit.
