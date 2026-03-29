// FieldFlow autocomplete patch
document.addEventListener('DOMContentLoaded', function () {
  let ffAutocompleteTimer;
  const input = document.querySelector('input[placeholder="Nome, morada ou contacto"]');
  if (!input) return;

  const resultsBox = document.createElement('div');
  resultsBox.className = 'ff-autocomplete-results';
  input.parentNode.appendChild(resultsBox);

  input.addEventListener('keyup', function () {
    const query = this.value.trim();
    clearTimeout(ffAutocompleteTimer);

    if (query.length < 3) {
      resultsBox.innerHTML = '';
      return;
    }

    ffAutocompleteTimer = setTimeout(() => {
      fetch(`/wp-json/fieldflow/v1/pdvs-search?q=${query}`)
        .then(res => res.json())
        .then(data => {
          if (!data || data.length === 0) {
            resultsBox.innerHTML = '<div class="ff-no-results">Sem resultados</div>';
            return;
          }

          resultsBox.innerHTML = data.map(item => `
            <div class="ff-item" data-id="${item.id}">
              <strong>${item.nome}</strong><br>
              <small>${item.cidade}</small>
            </div>
          `).join('');

          document.querySelectorAll('.ff-item').forEach(el => {
            el.addEventListener('click', function () {
              input.value = this.innerText;
              resultsBox.innerHTML = '';
            });
          });
        });
    }, 300);
  });
});
