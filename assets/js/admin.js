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

	/**
	 * Wraps a negative amount in parentheses instead of a plain minus sign
	 * (e.g. a discount line, or a heavily-discounted total) -- the standard
	 * accounting convention, matching KCRM_Invoice::format_money() on the
	 * PHP side.
	 */
	function formatMoney(amount) {
		// Round first so a sub-cent negative (float drift) that rounds to zero
		// doesn't get parenthesized as a misleading "(0.00)".
		var value = Math.round((parseFloat(amount) || 0) * 100) / 100;
		var formatted = Math.abs(value).toFixed(2);
		return value < 0 ? '(' + formatted + ')' : formatted;
	}

	function recalcRow($row) {
		var qty = parseFloat($row.find('.kcrm-item-quantity').val()) || 0;
		var rate = parseFloat($row.find('.kcrm-item-rate').val()) || 0;
		var amount = qty * rate;
		$row.find('.kcrm-item-amount').text(formatMoney(amount));
		return amount;
	}

	function recalcTotals() {
		var subtotal = 0;
		var taxableSubtotal = 0;
		$('#kcrm-line-items-body .kcrm-line-item').each(function () {
			var $row = $(this);
			var amount = recalcRow($row);
			subtotal += amount;
			if ($row.find('.kcrm-item-taxable').is(':checked')) {
				taxableSubtotal += amount;
			}
		});

		var taxRate = parseFloat($('#tax_rate').val()) || 0;
		var taxAmount = taxableSubtotal * (taxRate / 100);
		var total = subtotal + taxAmount;

		$('#kcrm-subtotal').text(formatMoney(subtotal));
		$('#kcrm-total').html('<strong>' + formatMoney(total) + '</strong>');
	}

	function bindRow($row) {
		$row.find('.kcrm-item-service').on('change', function () {
			var service = findService($(this).val());
			if (service) {
				var $desc = $row.find('.kcrm-item-description');
				if (!$desc.val()) {
					$desc.val(service.description || service.name);
				}
				$row.find('.kcrm-item-type').val(service.type);
				$row.find('.kcrm-item-rate').val(parseFloat(service.rate).toFixed(2));
				$row.find('.kcrm-item-taxable').prop('checked', !!service.is_taxable).trigger('change');
			}
			recalcTotals();
		});

		$row.find('.kcrm-item-quantity, .kcrm-item-rate').on('input', recalcTotals);
		$row.find('.kcrm-item-type, .kcrm-item-description').on('input change', recalcTotals);
		$row.find('.kcrm-item-taxable').on('change', function () {
			$row.find('.kcrm-item-taxable-value').val(this.checked ? '1' : '0');
			recalcTotals();
		});

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

	function toggleInvoiceBccEmailField() {
		var $checkbox = $('#invoice_bcc_enabled');
		if (!$checkbox.length) {
			return;
		}
		$('#kcrm-invoice-bcc-email-row').toggle($checkbox.is(':checked'));
	}

	$(function () {
		$('#invoice_type').on('change', toggleInvoiceTypeFields);
		toggleInvoiceTypeFields();

		$('#method').on('change', toggleMethodOtherField);
		toggleMethodOtherField();

		$('#invoice_bcc_enabled').on('change', toggleInvoiceBccEmailField);
		toggleInvoiceBccEmailField();

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
			$template.find('.kcrm-item-taxable').prop('checked', false);
			$template.find('.kcrm-item-taxable-value').val('0');
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

	$(function () {
		$('.kcrm-date-range-filter select[name$="_range"]').on('change', function () {
			var isCustom = this.value === 'custom';
			$(this).closest('form').find('.kcrm-date-range-custom').toggle(isCustom);
		});
	});

	$(function () {
		$('.kcrm-jobs-toggle').on('click', function (e) {
			e.preventDefault();
			var parentId = $(this).data('kcrm-jobs-parent');
			$('tr.kcrm-job-row[data-kcrm-jobs-parent="' + parentId + '"]').toggle();
			$(this).find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
		});
	});

	/**
	 * Collapse/expand toggle for a "Customers with Jobs" invoice group (see
	 * the Invoices list): unlike .kcrm-jobs-toggle above, these groups start
	 * expanded (the whole point is to show a customer's + its Jobs' invoices
	 * together), so the icon starts in the "expanded" state and flips on
	 * first click instead of the other way around.
	 */
	$(function () {
		$('.kcrm-invoice-group-toggle').on('click', function (e) {
			e.preventDefault();
			var $link = $(this);
			var groupId = $link.data('kcrm-invoice-group');
			var expanded = $link.data('kcrm-expanded') !== false;
			$('tr[data-kcrm-invoice-group="' + groupId + '"]').toggle(!expanded);
			$link.find('.dashicons').toggleClass('dashicons-arrow-up-alt2 dashicons-arrow-down-alt2');
			$link.data('kcrm-expanded', !expanded);
		});
	});

	$(function () {
		$('.kcrm-recent-actions-toggle').on('click', function (e) {
			e.preventDefault();
			var $link = $(this);
			var expanded = $link.data('kcrm-expanded') === true;
			$link.closest('.kcrm-recent-actions-wrap').find('.kcrm-recent-actions-extra').toggle(!expanded);
			$link.find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
			$link.find('.kcrm-recent-actions-toggle-label').text(expanded ? $link.data('kcrm-more-label') : $link.data('kcrm-less-label'));
			$link.data('kcrm-expanded', !expanded);
		});
	});

	/**
	 * "Also on file" CC suggestions in the Email Invoice modal: clicking one
	 * appends that address to the CC field instead of overwriting it, so
	 * multiple suggestions (or a suggestion plus something typed by hand)
	 * can be combined. The CC field itself starts blank -- these are just
	 * one-click shortcuts, not an auto-fill.
	 */
	$(function () {
		$('.kcrm-cc-suggestion').on('click', function (e) {
			e.preventDefault();
			var email = $(this).data('kcrm-cc-email');
			var $input = $('#email_cc');
			var current = $input.val().split(',').map(function (s) { return s.trim(); }).filter(Boolean);
			if (current.indexOf(email) === -1) {
				current.push(email);
			}
			$input.val(current.join(', '));
		});
	});

	/**
	 * Instant, client-side search for a wp-list-table: type into any
	 * `.kcrm-instant-search` input and its `data-kcrm-search-table` target
	 * table's rows are filtered by text match as you type, no page reload.
	 * A row tagged `data-kcrm-jobs-parent` (a child/detail row normally
	 * hidden behind a toggle, e.g. Jobs under a customer) is shown on match
	 * along with its parent row (found via `data-kcrm-customer-row`), so a
	 * search hit isn't left orphaned under a collapsed toggle.
	 */
	$(function () {
		$('.kcrm-instant-search').each(function () {
			var $input = $(this);
			var $table = $('#' + $input.data('kcrm-search-table'));
			var $tbody = $table.find('tbody');
			if (!$tbody.length) {
				return;
			}

			var $rows = $tbody.find('tr');
			var colspan = $table.find('thead th').length || 1;
			var $noResults = $('<tr class="kcrm-search-no-results" style="display:none;"><td colspan="' + colspan + '"></td></tr>');
			$noResults.find('td').text($input.data('kcrm-search-empty') || '');
			$tbody.append($noResults);

			var timer = null;
			$input.on('input', function () {
				clearTimeout(timer);
				timer = setTimeout(applyFilter, 150);
			});

			function applyFilter() {
				var term = $input.val().toString().trim().toLowerCase();

				if (!term) {
					$rows.each(function () {
						var $row = $(this);
						$row.toggle(typeof $row.data('kcrm-jobs-parent') === 'undefined');
					});
					$noResults.hide();
					return;
				}

				var visibleCount = 0;

				$rows.not('.kcrm-search-no-results').each(function () {
					var $row = $(this);
					if (typeof $row.data('kcrm-jobs-parent') !== 'undefined') {
						return;
					}
					var matches = $row.text().toLowerCase().indexOf(term) !== -1;
					$row.toggle(matches);
					if (matches) {
						visibleCount++;
					}
				});

				$rows.filter('[data-kcrm-jobs-parent]').each(function () {
					var $row = $(this);
					var matches = $row.text().toLowerCase().indexOf(term) !== -1;
					$row.toggle(matches);
					if (matches) {
						visibleCount++;
						$tbody.find('[data-kcrm-customer-row="' + $row.data('kcrm-jobs-parent') + '"]').show();
					}
				});

				$noResults.toggle(visibleCount === 0);
			}

			// A search term can arrive pre-filled (e.g. the Company Profile's
			// "Search Customers" box submits here with ?s=...) -- apply it
			// immediately instead of waiting for the user to type something.
			if ($input.val()) {
				applyFilter();
			}
		});
	});

	$(function () {
		var $form = $('#kcrm-receive-payment-form');
		if (!$form.length) {
			return;
		}

		var $amountInputs = $form.find('.kcrm-receive-payment-amount');

		function updateAllocatedTotal() {
			var total = 0;
			$amountInputs.each(function () {
				total += parseFloat($(this).val()) || 0;
			});
			$('#kcrm-receive-payment-allocated').text(total.toFixed(2));
		}

		$amountInputs.on('input', updateAllocatedTotal);
		updateAllocatedTotal();

		$('#kcrm-receive-payment-autofill').on('click', function () {
			var remaining = parseFloat($('#kcrm-receive-payment-total').val()) || 0;

			$form.find('#kcrm-receive-payment-body tr').each(function () {
				var $row = $(this);
				var balance = parseFloat($row.find('.kcrm-receive-payment-balance').data('balance')) || 0;
				var apply = Math.round(Math.min(remaining, balance) * 100) / 100;
				$row.find('.kcrm-receive-payment-amount').val(apply > 0 ? apply.toFixed(2) : '');
				remaining = Math.round((remaining - apply) * 100) / 100;
			});

			updateAllocatedTotal();
		});
	});

	$(function () {
		var $modal = $('#kcrm-email-modal');
		if (!$modal.length) {
			return;
		}

		$('#kcrm-open-email-modal').on('click', function () {
			$modal.show();
		});
		$('#kcrm-close-email-modal, .kcrm-modal-overlay').on('click', function (e) {
			if (e.target === this) {
				$modal.hide();
			}
		});
		$(document).on('keyup', function (e) {
			if (e.key === 'Escape') {
				$modal.hide();
			}
		});
	});
})(jQuery);
