// Reference design with retired selectors removed and explicitly approved UI adaptations.
// Parsing selector branches preserves shared targets, pseudo classes and media rules.
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const toolsPath = process.env.SPP_CSS_TOOLS;
if (!toolsPath) throw new Error('Set SPP_CSS_TOOLS to an external node_modules directory with postcss, postcss-selector-parser and css-tree.');
const postcss = require(path.join(toolsPath, 'postcss'));
const selectorParser = require(path.join(toolsPath, 'postcss-selector-parser'));

function pruneSelectors(container) {
  for (const selector of [...container.nodes]) {
    let retired = false;
    for (const node of [...selector.nodes]) {
      if (node.type === 'class' && /(?:deposit|titipan)/i.test(node.value)) retired = true;
      if (node.type === 'pseudo' && node.nodes?.length && node.nodes[0].type === 'selector') {
        pruneSelectors(node);
        if (!node.nodes.length) retired = true;
      }
    }
    if (retired) selector.remove();
  }
}

function referenceCss() {
  const original = execFileSync('git', ['show', '7647608:assets/css/style.css'], {
    cwd: path.join(__dirname, '..'), encoding: 'utf8', maxBuffer: 8 * 1024 * 1024,
  });
  const root = postcss.parse(original);
  root.walkRules(rule => {
    const parsed = selectorParser().astSync(rule.selector);
    pruneSelectors(parsed);
    if (!parsed.nodes.length) rule.remove();
    else rule.selector = parsed.toString();
  });
  root.walkAtRules(rule => { if (rule.nodes && !rule.nodes.length) rule.remove(); });
  root.walkComments(comment => {
    if (/(?:titipan|deposit)/i.test(comment.text)) {
      comment.text = comment.text.replace(/ dan titipan SPP| and SPP deposit history| and Riwayat Titipan SPP|, Riwayat Titipan SPP/gi, '')
        .replace(/Riwayat Titipan SPP dan /gi, '')
        .replace(/SPP deposit|Titipan SPP/gi, 'retired component');
    }
  });
  // The current logout is a CSRF-protected POST button, with the same old appearance.
  root.walkRules('.logout-btn', rule => {
    if (rule.parent.type !== 'root') return;
    for (const [prop, value] of Object.entries({ border: '0', background: 'none', font: 'inherit', cursor: 'pointer' })) {
      rule.append({ prop, value });
    }
  });
  // Approved compact unit badges: Role Management dimensions, existing data badge colors.
  root.walkRules('.unit-pill', rule => {
    rule.after(`span.unit-record-pill {
      display: inline-block;
      padding: 5px 10px;
      border-radius: 999px;
      background: rgba(245, 158, 11, .10);
      box-shadow: inset 0 0 0 1px rgba(245, 158, 11, .25);
      color: var(--orange);
      font-family: 'Inter', system-ui, sans-serif;
      font-size: 13px;
      font-weight: 800;
      line-height: 1.6;
      vertical-align: middle;
      white-space: nowrap;
    }`);
  });
  // Approved four-button Dashboard scope control, including the mobile two-row layout.
  root.walkRules('.dashboard-scope-card', rule => {
    if (rule.parent.type === 'root') rule.nodes.find(node => node.prop === 'display').after({ prop: 'flex-wrap', value: 'wrap' });
  });
  root.walkRules('.dashboard-scope-options', rule => {
    if (rule.parent.type === 'root') {
      rule.nodes.find(node => node.prop === 'grid-template-columns').value = 'repeat(4, minmax(0, 1fr))';
      const minimum = rule.nodes.find(node => node.prop === 'min-width');
      minimum.value = '360px';
      minimum.after({ prop: 'margin', value: '0' });
    } else {
      rule.append({ prop: 'grid-template-columns', value: 'repeat(2, minmax(0, 1fr))' });
      rule.after('.dashboard-scope-option { min-height: 44px; }');
    }
  });
  root.walkRules('.dashboard-scope-option', rule => {
    if (rule.parent.type !== 'root') return;
    rule.nodes.find(node => node.prop === 'border-radius').after({ prop: 'border', value: '0' });
    rule.nodes.find(node => node.prop === 'border').after({ prop: 'background', value: 'transparent' });
    rule.nodes.find(node => node.prop === 'font-size').after({ prop: 'font-family', value: 'inherit' });
    rule.nodes.find(node => node.prop === 'white-space').after({ prop: 'cursor', value: 'pointer' });
  });
  return root.toString();
}

module.exports = { referenceCss, postcss, selectorParser };
