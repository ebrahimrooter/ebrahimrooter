var q = new URLSearchParams(location.search);
if (q.get('a') === 'pair' && q.get('code')) {
  document.getElementById('pair').hidden = false;
  document.getElementById('code').textContent = q.get('code');
  document.getElementById('server').textContent = q.get('server') || '';
  document.getElementById('open').href = 'bankassistant://pair?code=' + encodeURIComponent(q.get('code')) + '&server=' + encodeURIComponent(q.get('server') || '');
}
