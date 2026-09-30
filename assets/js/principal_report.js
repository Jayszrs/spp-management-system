(() => {
  const search = document.querySelector('[data-principal-search]');
  if (!search) return;

  const rows = [...document.querySelectorAll('[data-principal-row]')];
  const empty = document.querySelector('[data-principal-search-empty]');

  function filterRows() {
    const term = search.value.trim().toLocaleLowerCase('id');
    let visible = 0;
    for (const row of rows) {
      const matches = (row.dataset.searchText || '').toLocaleLowerCase('id').includes(term);
      row.hidden = !matches;
      if (matches) visible++;
    }
    if (empty) empty.hidden = term === '' || visible > 0;
  }

  search.addEventListener('input', filterRows);
  search.addEventListener('search', filterRows);
})();
