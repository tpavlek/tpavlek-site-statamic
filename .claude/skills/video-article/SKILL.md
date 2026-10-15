---
name: video-article
description: Draft a companion article and share card for one of Troy's short videos, from the published video and its raw files in ~/Downloads/Video Scratch. Use when Troy hands over a video link plus a scratch folder and asks for a post, article, or write-up of a video.
---

# Video companion article

Troy gives you a published video (a YouTube/Facebook/Instagram link, or a site URL) and its
scratch folder in `~/Downloads/Video Scratch/<project>/`. You produce:

1. The video entry, complete (create it if it doesn't exist).
2. A companion article in the entry's `article` field, faithful to the video's script.
3. A 1200×630 share card (`og_image`) if the entry doesn't have one.

Then you show it to him rendered and wait for notes. **Never commit until he says so.**

Background lives in project memory; read these before starting:
`video-companion-articles`, `video-entry-scaffolding`, `video-share-cards`,
`video-follow-up-threads`, `story-bard-sets`, `new-copy-in-troys-voice`.

## 1. The entry

- Find it in `content/collections/videos/` by YouTube ID first, then title. Entries may
  have `instagram: ''`, so a missing Instagram ID doesn't mean a missing entry.
- If none exists, create one: YouTube metadata via `yt-dlp --print` (upload date →
  `publish_date`, description → body), `*-captioned.mp4` → `storage/app/video-sources/`,
  `*-cover.png` → `public/assets/videos/covers/`.
- Fill every platform ID Troy gives you (`youtube`, `instagram` = the reel/post code,
  `facebook` = the reel number, quoted).
- If it continues an earlier video, set `follows_up` on the **newer** one.
- Topics: reuse existing terms in `content/taxonomies/topics/`. Ask before inventing one
  unless Troy says more are coming.

## 2. The script

- Pull captions from YouTube: `yt-dlp --skip-download --write-auto-subs --sub-langs
  "en-orig,en" --sub-format vtt`. Fall back to `mlx_whisper` on the captioned mp4 if it 429s.
  Whisper has hung in the background before; run it in the foreground.
- Fix mishearings (Whyte not White, names, "our mature" not "armature").
- If the video quotes an interview, check who said what against the full recording's captions.

## 3. Writing it

- **Keep the script.** Same order, same sentences, Troy's words. New copy is limited to
  headings, captions, and an occasional update line, and all of it is flagged for review.
- Keep his jokes as their own beats: a line on its own, italics, a pull quote, or a set.
  Humour that only lands with timing on video needs a visual equivalent (a silence
  divider, a stamp, a captioned still).
- No em dashes. Never "X", always "Twitter". Don't editorialize titles; Troy picks them.
- Don't add "Update:" blocks on your own. Offer one if news has moved on; he's turned
  them down before.

### Visuals from the scratch folder

- `.mov` clips that just highlight a headline become **links** in the sentence, not images.
  Grab a frame only to learn which article to find and link.
- Real photos, charts, his own graphics, social posts (kept whole) and memes stay as images.
- Look for links at the original outlet. If you can only find other coverage, use it and
  tell Troy which links are stand-ins. Never invent a URL or a figure; unsourced script
  claims stay unlinked and get flagged.
- If a source page has changed since the video, host the saved copy from the scratch
  folder (e.g. a PDF) and link that.

### Story sets worth reaching for (`resources/fieldsets/long_form.yaml`)

`timeline`, `stats`, `source_quote` (someone else's words; with speaker + photo it's a
speech bubble, optional `stamp`), `dialogue` (style `chat` = chatroom interview with
photos from `people`, speaker-less line = system divider), `punchline` (closing line,
optional `sticker`), `callout`, plus `image` / `gallery` / `social_embed`.
The bare quote button is a big centred pull quote; use it for his own lines only.

Generate the Bard YAML with a small Python script (helpers for paragraph/link/set) and
splice it into the entry's front matter, so reruns are cheap when he asks for changes.

## 4. The share card

Follow memory `video-share-cards`. In short:

- Check `*-cover.png` first. If the Short has its own look, match it, and ask Troy for any
  background image that isn't in the folder.
- Otherwise: Proxima Nova Extrabold headline on solid colour blocks, over a blurred photo
  from the article, minimal text.
- Troy's cutout comes from the **original phone file** (`PXL_*.mp4`, or `*-record.mov`,
  which may be stored sideways). Sample at 2 fps, rank by face sharpness, pick the
  sharpest frame with a fitting expression, cut it out with rembg `birefnet-portrait`.
- Render HTML at 1200×630 with hyperframes' chrome-headless-shell at 2x, downsample, save to
  `public/assets/og/`, set `og_image`, add `alt` to the asset's `.meta` yaml.

## 5. Finish

- `php please assets:meta assets`, then **revert the unrelated `.meta` churn** it causes
  (`git checkout` every modified `public/assets/**/.meta/` file that isn't yours).
- `php please stache:refresh`, then check the page returns 200 and screenshot it (browser
  extension, or headless Chrome) to review your own layout before handing over.
- Report: the review URL, the new words to check, stand-in or missing links, and what's
  left. Commit only Troy's go-ahead, staging only this work's files; he often has
  unrelated changes in the tree.
