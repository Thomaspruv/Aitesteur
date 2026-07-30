# Airtight — Design System v1.0

> SaaS B2B · QA & tests logiciels
> Direction : minimaliste tech, dark-first, wordmark pur
> Dernière mise à jour : 22 juillet 2026

---

## 1. Essence de la marque

**Airtight** signifie « hermétique, sans faille ». La marque promet une seule chose : du code étanche, des releases qui ne cassent rien.

- **Positionnement** : l'outil QA qui rend le code hermétique.
- **Personnalité** : précis, calme, sûr de lui. Jamais bavard, jamais alarmiste.
- **Tagline** : *Ship airtight code.*
- **Signature visuelle** : le point final. La phrase est close, le sujet est réglé.

### Voix & ton

- Phrases courtes. Affirmatives. Terminées par un point.
- Anglais technique simple, pas de jargon marketing (« synergy », « empower »…).
- L'humour est permis en creux (messages d'état, empty states), jamais dans les moments critiques (un test qui échoue n'est pas drôle).
- Exemples de micro-copy : `All tests passed.` · `Nothing broke. Ship it.` · `3 leaks found.` (on dit *leak*, pas *bug* — cohérence avec l'étanchéité).

---

## 2. Logo

### 2.1 Le wordmark

Le logo est **typographique uniquement** : `airtight` en bas de casse, suivi d'un **point cyan**.

```
airtight.
        ↑ le point est TOUJOURS en accent (cyan), le mot toujours en couleur de texte
```

**Construction :**

| Propriété | Valeur |
|---|---|
| Police | Inter Bold (700) — fallback : system-ui bold |
| Casse | bas de casse exclusivement, jamais « Airtight. » ni « AIRTIGHT. » dans le logo |
| Interlettrage | -0.02em |
| Le point | même corps que le texte, couleur `--accent`, collé au « t » (pas d'espace) |
| Couleur du mot | `#e8eaf0` sur fond sombre · `#12151c` sur fond clair |
| Couleur du point | `#38bdf8` sur fond sombre · `#0284c7` sur fond clair |

En HTML/CSS :

```html
<span class="logo">airtight<span class="logo-dot">.</span></span>
```

```css
.logo {
  font-family: "Inter", system-ui, sans-serif;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--text-primary);
}
.logo-dot { color: var(--accent); }
```

### 2.2 Le monogramme (favicon, avatar, icône d'app)

Quand le wordmark complet ne rentre pas : **`a.`** — le « a » en couleur de texte, le point en cyan. Mêmes règles (Inter Bold, bas de casse).

- Favicon 16/32 px : `a.` centré sur fond `#0a0c10`, coins du canvas droits.
- Icône d'app (macOS/iOS/PWA) : `a.` centré sur fond `#0a0c10`, le conteneur applique son propre arrondi.
- Avatar réseaux sociaux : identique à l'icône d'app.

### 2.3 Zone de protection et tailles minimales

- **Clearspace** : autour du wordmark, réserver une marge égale à la hauteur du « a » (x-height) sur les 4 côtés. Rien ne doit entrer dans cette zone.
- **Taille minimale wordmark** : 90 px de large (≈ 20 px de corps). En dessous, utiliser le monogramme `a.`.
- **Taille minimale monogramme** : 16 px.

### 2.4 Interdits

- ❌ Ne jamais colorer le mot entier en cyan.
- ❌ Ne jamais mettre le point en une autre couleur que l'accent (ni blanc, ni vert — le vert est banni de la marque).
- ❌ Ne jamais ajouter d'icône, de symbole ou de forme à côté du wordmark.
- ❌ Ne jamais utiliser d'italique, d'outline, d'ombre portée ou de dégradé sur le logo.
- ❌ Ne jamais écrire « AirTight », « Air Tight » ou « Airtight ! ».
- ❌ Pas de version majuscule : le bas de casse fait partie de l'identité.

---

## 3. Couleur

Dark-first : le produit, le site et les documents sont sombres par défaut. Le mode clair existe mais il est secondaire.

### 3.1 Fondations (mode sombre — défaut)

| Token | Hex | Usage |
|---|---|---|
| `--bg` | `#0a0c10` | Fond de page |
| `--bg-raised` | `#12151c` | Cartes, panneaux |
| `--bg-overlay` | `#161a23` | Hover de cartes, dropdowns, modales |
| `--bg-inset` | `#0d1016` | Zones enfoncées : code, terminaux, inputs |
| `--border` | `#232834` | Bordures, séparateurs |
| `--border-strong` | `#323949` | Bordures au hover/focus (hors accent) |
| `--text-primary` | `#e8eaf0` | Titres, texte principal |
| `--text-secondary` | `#8b93a5` | Texte secondaire, descriptions |
| `--text-tertiary` | `#5a6172` | Labels, métadonnées, placeholders |

### 3.2 Accent — « Oxygen »

Une seule couleur d'accent. Elle est rare à l'écran : c'est ce qui la rend précieuse.

| Token | Hex | Usage |
|---|---|---|
| `--accent` | `#38bdf8` | Le point du logo, CTA primaires, liens, focus rings, éléments actifs |
| `--accent-hover` | `#5cc9f9` | Hover des éléments accent |
| `--accent-muted` | `rgba(56,189,248,0.12)` | Fonds de badges, sélections, highlights |
| `--accent-border` | `rgba(56,189,248,0.3)` | Bordures de badges accent |
| `--accent-on` | `#062a3d` | Texte posé SUR un fond accent plein |

**Règle d'or : un seul élément accent fort par vue.** Si tout est cyan, rien n'est cyan.

### 3.3 Couleurs sémantiques (états de tests)

Le vert est banni, y compris pour les états. Le « pass » est cyan : c'est la marque elle-même qui dit que ça passe.

| Token | Hex | Usage |
|---|---|---|
| `--pass` | `#38bdf8` | Test réussi, build OK (= accent) |
| `--fail` | `#fb7185` | Test échoué, erreur |
| `--warn` | `#f59e0b` | Test flaky, avertissement, skipped-with-reason |
| `--neutral` | `#8b93a5` | Test skipped, pending, désactivé |

Chaque sémantique a ses déclinaisons `-muted` (fond à 12 % d'opacité) et `-border` (30 %), sur le modèle de l'accent.

### 3.4 Mode clair (secondaire)

| Token | Hex |
|---|---|
| `--bg` | `#f5f6f8` |
| `--bg-raised` | `#ffffff` |
| `--border` | `#e2e5ea` |
| `--text-primary` | `#12151c` |
| `--text-secondary` | `#5a6172` |
| `--accent` | `#0284c7` |
| `--fail` | `#e11d48` |
| `--warn` | `#b45309` |

**Contraste** : tous les couples texte/fond respectent WCAG AA (≥ 4.5:1 pour le texte courant, ≥ 3:1 pour le texte large et les éléments d'UI). `#38bdf8` sur `#0a0c10` ≈ 8.5:1 ✓. Ne jamais poser `--text-tertiary` sur `--bg-inset` pour du texte porteur de sens.

---

## 4. Typographie

Deux familles, pas une de plus.

| Rôle | Police | Graisses |
|---|---|---|
| UI & titres | **Inter** (variable) | 400, 500, 600, 700 |
| Code, logs, données | **JetBrains Mono** — fallback : ui-monospace | 400, 500 |

### Échelle

| Token | Taille / interligne | Graisse | Usage |
|---|---|---|---|
| `display` | 32 / 38 px | 700 | Titres de pages marketing |
| `h1` | 24 / 30 px | 700 | Titre d'écran produit |
| `h2` | 18 / 24 px | 600 | Section |
| `h3` | 15 / 20 px | 600 | Sous-section, titres de cartes |
| `body` | 14.5 / 22 px | 400 | Texte courant |
| `small` | 13 / 18 px | 400 | Texte secondaire |
| `caption` | 11 / 14 px | 600 | Labels en capitales, letter-spacing 0.1em |
| `code` | 13 / 20 px | 400 mono | Code, identifiants de tests, durées |

Règles : les titres sont en bas de casse (« Test runs », pas « Test Runs ») — cohérence avec le logo. Les chiffres tabulaires (`font-variant-numeric: tabular-nums`) sont obligatoires dans les tableaux et les durées.

---

## 5. Espacement, rayons, élévation

### Espacement — base 4 px

`4 · 8 · 12 · 16 · 24 · 32 · 48 · 64`

Padding standard des cartes : 24 px. Gouttières de grille : 24 px. Marges de page : 24 px (mobile) / 40 px (desktop).

### Rayons

| Token | Valeur | Usage |
|---|---|---|
| `--radius-s` | 6 px | Badges, chips, inputs compacts |
| `--radius-m` | 10 px | Boutons, inputs |
| `--radius-l` | 14 px | Cartes, modales |
| `--radius-full` | 999 px | Pills, avatars, points d'état |

### Élévation

Le dark mode s'élève par la **couleur de fond et la bordure**, pas par l'ombre : `--bg` → `--bg-raised` → `--bg-overlay`. Les ombres sont réservées aux éléments flottants (dropdowns, modales) : `0 8px 24px rgba(0,0,0,0.4)`.

---

## 6. Composants — règles clés

### Boutons

- **Primaire** : fond `--accent`, texte `--accent-on`, radius M. Un seul par vue.
- **Secondaire** : fond transparent, bordure `--border`, texte `--text-primary`.
- **Ghost** : texte `--text-secondary`, hover `--bg-overlay`.
- **Destructif** : réservé aux suppressions ; fond `--fail` uniquement à la confirmation.
- Hauteurs : 36 px (défaut) / 30 px (compact). Jamais de majuscules dans les libellés.

### États de tests (le composant cœur du produit)

Un état = un **point** (radius full, 8 px) + un libellé, jamais une couleur seule :

- `● passed` — point `--pass`, libellé `--text-secondary`
- `● failed` — point `--fail`, libellé `--fail`
- `● flaky` — point `--warn`, libellé `--warn`
- `○ skipped` — cercle vide `--neutral`

La couleur ne porte jamais l'information seule (accessibilité daltoniens) : toujours doubler par le libellé ou une icône.

### Inputs

Fond `--bg-inset`, bordure `--border`, radius M, hauteur 36 px. Focus : bordure `--accent` + ring `--accent-muted` 3 px. Placeholder en `--text-tertiary`.

### Badges

Fond `-muted`, bordure `-border`, texte de la couleur pleine, radius full, caption 11 px.

### Terminal / blocs de code

Fond `--bg-inset`, bordure `--border`, JetBrains Mono 13 px. Le prompt est `--accent` : `❯`. Les logs de succès se terminent par `airtight.` en cyan — signature produit.

---

## 7. Data viz (dashboards de runs)

- Fond des graphiques : transparent sur `--bg-raised`.
- Série principale : `--accent`. Séries secondaires : `#818cf8`, `#8b93a5`.
- Pass rate dans le temps : aire cyan à 12 % d'opacité, ligne pleine.
- Échecs : toujours `--fail`, jamais une autre teinte de rouge.
- Grilles : `--border` en 1 px, pas de grille verticale.
- Pas de dégradés multicolores, pas de camemberts (préférer barres empilées pass/fail/skip).

---

## 8. Assets à produire (checklist v1)

- [ ] `logo-dark.svg` — wordmark sur fond sombre (texte vectorisé)
- [ ] `logo-light.svg` — wordmark sur fond clair
- [ ] `monogram.svg` — `a.` seul
- [ ] `favicon.ico` + `favicon.svg` + `apple-touch-icon.png` (180 px)
- [ ] `og-image.png` (1200 × 630) — wordmark centré sur `#0a0c10`, tagline dessous
- [ ] PNG haute résolution du wordmark (1x, 2x, 4x) pour les usages hors web

**Note dépôt de marque** : déposer en semi-figuratif (wordmark stylisé « airtight. ») en plus du verbal, classes 9 et 42, INPI puis EUIPO. Le point cyan fait partie du signe déposé.

---

## 9. Tokens CSS — prêt à coller

```css
:root {
  /* fondations dark (défaut) */
  --bg: #0a0c10;
  --bg-raised: #12151c;
  --bg-overlay: #161a23;
  --bg-inset: #0d1016;
  --border: #232834;
  --border-strong: #323949;
  --text-primary: #e8eaf0;
  --text-secondary: #8b93a5;
  --text-tertiary: #5a6172;

  /* accent */
  --accent: #38bdf8;
  --accent-hover: #5cc9f9;
  --accent-muted: rgba(56, 189, 248, 0.12);
  --accent-border: rgba(56, 189, 248, 0.30);
  --accent-on: #062a3d;

  /* sémantique */
  --pass: #38bdf8;
  --fail: #fb7185;
  --fail-muted: rgba(251, 113, 133, 0.12);
  --warn: #f59e0b;
  --warn-muted: rgba(245, 158, 11, 0.12);
  --neutral: #8b93a5;

  /* rayons */
  --radius-s: 6px;
  --radius-m: 10px;
  --radius-l: 14px;
  --radius-full: 999px;

  /* typo */
  --font-ui: "Inter", system-ui, sans-serif;
  --font-mono: "JetBrains Mono", ui-monospace, monospace;
}

[data-theme="light"] {
  --bg: #f5f6f8;
  --bg-raised: #ffffff;
  --bg-overlay: #eef0f4;
  --bg-inset: #eceef2;
  --border: #e2e5ea;
  --border-strong: #c9ced8;
  --text-primary: #12151c;
  --text-secondary: #5a6172;
  --text-tertiary: #8b93a5;
  --accent: #0284c7;
  --accent-hover: #0369a1;
  --accent-muted: rgba(2, 132, 199, 0.10);
  --accent-border: rgba(2, 132, 199, 0.30);
  --accent-on: #ffffff;
  --pass: #0284c7;
  --fail: #e11d48;
  --warn: #b45309;
}
```

---

*airtight.*
