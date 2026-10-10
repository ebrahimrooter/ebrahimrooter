// phone -> the deposits/withdrawals app with the voice orb; computer -> the accounting panel
var phone = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
if (!/[?&]stay/.test(location.search)) location.replace(phone ? 'app/' : 'acc/');
