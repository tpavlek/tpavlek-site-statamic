/*
 * Generates resources/css/icons.css from the Font Awesome Free SVG sources.
 *
 * Why this exists: the site used to load FA as a kit <script> from their CDN, in <head>,
 * render-blocking, pulling down a JS runtime and webfonts to draw fifteen glyphs. That was
 * the single largest thing standing between this site and a good LCP.
 *
 * Why CSS masks rather than inlining <svg> into the templates: icons are not all in
 * templates. The home page reads its icon classes out of content (`icon: "fab fa-tiktok"`
 * in home.md), and the ticket and review partials build classes inline. A mask-image keyed
 * on the same `.fa-tiktok` class name keeps every one of those working untouched — the
 * markup, the content, and `text-teal`/`text-xl` sizing all behave exactly as before,
 * because the mask paints with currentColor at 1em.
 *
 * Only the icons the site actually uses are emitted. Adding one to a template or to content
 * means adding it to ICONS below and rebuilding; a missing icon renders as nothing, which is
 * visible in review rather than silently broken.
 *
 *   node build/icons.mjs
 *
 * Runs automatically as part of `npm run build`.
 */

import { readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const svgs = resolve(root, 'node_modules/@fortawesome/fontawesome-free/svgs')

/**
 * Every icon the site references, from templates and from content both. Keep in sync with:
 *   grep -rhoE 'fa-[a-z0-9-]+' resources/views content
 */
const ICONS = {
  brands: ['bluesky', 'facebook', 'instagram', 'linkedin', 'reddit', 'tiktok', 'twitter', 'youtube'],
  solid: ['globe', 'history', 'image', 'link', 'location-dot', 'pencil', 'play'],
}

/**
 * The SVG, as a data URI fit for mask-image.
 *
 * Left as a UTF-8 data URI rather than base64: it stays readable in devtools and is smaller,
 * since base64 adds a third to every byte. `#` must be encoded or it terminates the URL.
 */
function dataUri(category, name) {
  const svg = readFileSync(resolve(svgs, category, `${name}.svg`), 'utf8')
    // The licence comment is repeated in every file; it's carried once in the CSS header
    // instead, which is what the CC BY 4.0 attribution requires.
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\s+/g, ' ')
    .trim()

  return `url("data:image/svg+xml,${svg.replace(/"/g, "'").replace(/%/g, '%25').replace(/#/g, '%23').replace(/</g, '%3C').replace(/>/g, '%3E')}")`
}

const rules = Object.entries(ICONS).flatMap(([category, names]) =>
  names.map((name) => {
    // `-webkit-mask-image` alongside the standard property: Safari only unprefixed
    // mask-image in 15.4, and older iOS is well represented among people checking a
    // festival listing on the phone in their pocket.
    return `.fa-${name}{-webkit-mask-image:${dataUri(category, name)};mask-image:${dataUri(category, name)}}`
  }),
)

const css = `/*
 * GENERATED FILE — do not edit. Run \`node build/icons.mjs\` (or \`npm run build\`).
 * Source: build/icons.mjs
 *
 * Font Awesome Free 7.3.1 by @fontawesome — https://fontawesome.com
 * Icons: CC BY 4.0 — https://fontawesome.com/license/free
 */

/*
 * The shared box. Every icon is a 1em square painted in the current text colour through an
 * SVG mask, so \`text-teal\`, \`text-xl\` and friends keep working exactly as they did against
 * the icon font.
 *
 * The class selectors match Font Awesome's own — \`fas\`, \`fab\`, \`fa-solid\`, \`fa-brands\` —
 * because both spellings appear in the templates and in content, and rewriting content to
 * suit the build would be the wrong way round.
 */
.fa,.fas,.fab,.far,.fa-solid,.fa-brands,.fa-regular{
  display:inline-block;
  width:1em;
  height:1em;
  /* Sits the glyph on the text baseline the way the font did; without it icons ride high
     against adjacent text. */
  vertical-align:-0.125em;
  background-color:currentColor;
  -webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;
  -webkit-mask-position:center;mask-position:center;
  -webkit-mask-size:contain;mask-size:contain;
}

${rules.join('\n')}
`

writeFileSync(resolve(root, 'resources/css/icons.css'), css)

console.log(`icons.css: ${rules.length} icons, ${(css.length / 1024).toFixed(1)}KB`)
