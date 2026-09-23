// Dumps starterDoc() from a bento checkout's slides/src/starterdeck.ts to
// __starter-dump.json in that same directory. Run via tsx (handles the .ts
// import directly, no separate compile step) — see build.mjs.
import { pathToFileURL } from 'node:url'
import { writeFileSync } from 'node:fs'
import { join } from 'node:path'

const srcDir = process.argv[2]
if (!srcDir) {
  console.error('Usage: dump-starter.mjs <path-to-bento/slides/src>')
  process.exit(1)
}

const mod = await import(pathToFileURL(join(srcDir, 'starterdeck.ts')).href)
const doc = mod.starterDoc()
writeFileSync(join(srcDir, '__starter-dump.json'), JSON.stringify(doc))
console.log(`dumped ${doc.slides.length} slides, ${Object.keys(doc.assets || {}).length} assets`)
