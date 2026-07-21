(function ($) {
	'use strict';

	function servicesList() {
		return window.kcrmServices || [];
	}

	function findService(id) {
		var services = servicesList();
		for (var i = 0; i < services.length; i++) {
			if (parseInt(services[i].id, 10) === parseInt(id, 10)) {
				return services[i];
			}
		}
		return null;
	}

	function recalcRow($row) {
		var qty = parseFloat($row.find('.kcrm-item-quantity').val()) || 0;
		var rate = parseFloat($row.find('.kcrm-item-rate').val()) || 0;
		var amount = qty * rate;
		$row.find('.kcrm-item-amount').text(amount.toFixed(2));
		return amount;
	}

	function recalcTotals() {
		var subtotal = 0;
		$('#kcrm-line-items-body .kcrm-line-item').each(function () {
			subtotal += recalcRow($(this));
		});

		var taxRate = parseFloat($('#tax_rate').val()) || 0;
		var taxAmount = subtotal * (taxRate / 100);
		var total = subtotal + taxAmount;

		$('#kcrm-subtotal').text(subtotal.toFixed(2));
		$('#kcrm-total').html('<strong>' + total.toFixed(2) + '</strong>');
	}

	function bindRow($row) {
		$row.find('.kcrm-item-service').on('change', function () {
			var service = findService($(this).val());
			if (service) {
				var $desc = $row.find('.kcrm-item-description');
				if (!$desc.val()) {
					$desc.val(service.name);
				}
				$row.find('.kcrm-item-type').val(service.type);
				$row.find('.kcrm-item-rate').val(parseFloat(service.rate).toFixed(2));
			}
			recalcTotals();
		});

		$row.find('.kcrm-item-quantity, .kcrm-item-rate').on('input', recalcTotals);
		$row.find('.kcrm-item-type, .kcrm-item-description').on('input change', recalcTotals);

		$row.find('.kcrm-remove-line').on('click', function () {
			if ($('#kcrm-line-items-body .kcrm-line-item').length > 1) {
				$row.remove();
				recalcTotals();
			} else {
				$row.find('input').val('');
				recalcTotals();
			}
		});
	}

	function toggleInvoiceTypeFields() {
		var $select = $('#invoice_type');
		if (!$select.length) {
			return;
		}
		var type = $select.val();
		$('#kcrm-invoice-type-month-row').toggle(type === 'month_year');
		$('#kcrm-invoice-type-other-row').toggle(type === 'other');
	}

	function toggleMethodOtherField() {
		var $select = $('#method');
		if (!$select.length || 'select' !== $select.prop('tagName').toLowerCase()) {
			return;
		}
		$('#kcrm-method-other-row').toggle($select.val() === '__other__');
	}

	$(function () {
		$('#invoice_type').on('change', toggleInvoiceTypeFields);
		toggleInvoiceTypeFields();

		$('#method').on('change', toggleMethodOtherField);
		toggleMethodOtherField();

		var $body = $('#kcrm-line-items-body');
		if (!$body.length) {
			return;
		}

		$body.find('.kcrm-line-item').each(function () {
			bindRow($(this));
		});
		recalcTotals();

		$('#tax_rate').on('input', recalcTotals);

		$('#kcrm-add-line').on('click', function () {
			var $template = $body.find('.kcrm-line-item').first().clone();
			$template.find('input[type=text], input[type=number]').val('');
			$template.find('.kcrm-item-quantity').val('1');
			$template.find('.kcrm-item-rate').val('0.00');
			$template.find('.kcrm-item-service').val('0');
			$template.find('.kcrm-item-amount').text('0.00');
			$body.append($template);
			bindRow($template);
			recalcTotals();
		});
	});

	function bindPaymentLinkRow($row) {
		$row.find('.kcrm-remove-payment-link').on('click', function () {
			var $rows = $('#kcrm-payment-links-body .kcrm-payment-link-row');
			if ($rows.length > 1) {
				$row.remove();
			} else {
				$row.find('input').val('');
			}
		});
	}

	$(function () {
		var $linksBody = $('#kcrm-payment-links-body');
		if (!$linksBody.length) {
			return;
		}

		$linksBody.find('.kcrm-payment-link-row').each(function () {
			bindPaymentLinkRow($(this));
		});

		$('#kcrm-add-payment-link').on('click', function () {
			var $template = $linksBody.find('.kcrm-payment-link-row').first().clone();
			$template.find('input').val('');
			$linksBody.append($template);
			bindPaymentLinkRow($template);
		});
	});
})(jQuery);
