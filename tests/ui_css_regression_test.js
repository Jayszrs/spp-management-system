// Install test tooling outside the repo; see ui_css_reference.js.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { referenceCss, postcss, selectorParser } = require('./ui_css_reference');
const cssTree = require(path.join(process.env.SPP_CSS_TOOLS, 'css-tree'));
const css = fs.readFileSync(path.join(__dirname, '../assets/css/style.css'), 'utf8');
const errors = [];
cssTree.parse(css, { onParseError: error => errors.push(error.message) });
assert.deepEqual(errors, [], 'CSS parser must not recover damaged rules');
const parsed = postcss.parse(css);
parsed.walkRules(rule => {
  selectorParser().astSync(rule.selector);
  assert.doesNotMatch(rule.selector, /(?:deposit|titipan)/i, 'Retired selectors must stay removed');
});
function canonical(source) {
  const ast = cssTree.parse(source);
  return cssTree.generate(ast);
}
assert.equal(canonical(css), canonical(referenceCss()), 'All retained design rules must match 7647608 plus the logout button and compact unit pill adaptations');
assert.match(css, /data-palette="sma"/);
assert.match(css, /data-palette="smp"/);
assert.match(css, /\.savings-print/);
assert.match(css, /\.authorization-hero/);
// Detect stale stylesheet references on all actual PHP pages.
function checkPages(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (['.git', 'vendor', 'tmp', 'temp', 'tests'].includes(entry.name)) continue;
    const full = path.join(directory, entry.name);
    if (entry.isDirectory()) checkPages(full);
    else if (entry.name.endsWith('.php')) {
      const text = fs.readFileSync(full, 'utf8');
      for (const match of text.matchAll(/href="[^"\n]*assets\/css\/style\.css[^"\n]*"/g)) {
        assert.match(match[0], /filemtime\(/, `Stylesheet cache version missing: ${full}`);
      }
    }
  }
}
checkPages(path.join(__dirname, '..'));
console.log('OK: CSS syntax, retained reference design, three unit palettes and filemtime asset versions');
