(() => {
  const form = document.getElementById('legacy-activation');
  if (!form) return;
  const rates = JSON.parse(document.getElementById('legacy-rates').textContent);
  const refresh = () => {
    const rate = rates[form.year_id.value]?.[form.class_id.value];
    form.spp.value = rate?.net || 0;
    document.getElementById('legacy-rate-status').textContent = rate
      ? `Master SPP: ${rate.year}, Rp ${Number(rate.net).toLocaleString('id-ID')}. Tarif sumber legacy tidak diterapkan.`
      : 'Pilih rombel untuk melihat tarif master.';
    form.confirmed.checked = false;
  };
  form.year_id.addEventListener('change', refresh);
  form.class_id.addEventListener('change', refresh);
})();
