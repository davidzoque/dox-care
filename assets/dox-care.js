/* Dox Care: envío del formulario "Pedir un cambio" y la ventana de la barra superior. */
(function () {
	'use strict';

	function send(form) {
		var msg = form.querySelector('.dxc-form-msg');
		var btn = form.querySelector('button[type="submit"]');
		var text = form.querySelector('textarea[name="message"]');
		if (!text.value.trim()) {
			text.focus();
			return;
		}
		btn.disabled = true;
		msg.className = 'dxc-form-msg';
		msg.textContent = DoxCare.sending;

		fetch(DoxCare.ajax, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				var ok = res && res.success;
				msg.className = 'dxc-form-msg ' + (ok ? 'is-ok' : 'is-error');
				msg.textContent = (res && res.data && res.data.message) || DoxCare.error;
				if (ok) {
					form.reset();
				}
			})
			.catch(function () {
				msg.className = 'dxc-form-msg is-error';
				msg.textContent = DoxCare.error;
			})
			.then(function () { btn.disabled = false; });
	}

	document.addEventListener('submit', function (e) {
		if (e.target.classList && e.target.classList.contains('dxc-form')) {
			e.preventDefault();
			send(e.target);
		}
	});

	// Ventana de la barra superior.
	var modal = document.getElementById('dox-care');
	if (!modal) {
		return;
	}
	function open(e) {
		if (e) { e.preventDefault(); }
		modal.hidden = false;
		document.documentElement.classList.add('dxc-lock');
		var t = modal.querySelector('textarea');
		setTimeout(function () { t && t.focus(); }, 50);
	}
	function close() {
		modal.hidden = true;
		document.documentElement.classList.remove('dxc-lock');
	}
	document.addEventListener('click', function (e) {
		if (e.target.closest('#wp-admin-bar-dox-care-request a')) {
			open(e);
		} else if (e.target.closest('[data-dxc-close]')) {
			close();
		}
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !modal.hidden) { close(); }
	});
})();
