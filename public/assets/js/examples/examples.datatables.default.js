/*
Name: 			Tables / Advanced - Examples
Written by: 	Okler Themes - (http://www.okler.net)
Theme Version: 	4.0.0
*/

(function($) {

	'use strict';

	var datatableInit = function() {

		$('#datatable-default').dataTable({
			dom: '<"row"<"col-lg-6"l><"col-lg-6"f>><"table-responsive"t>', // Removed pagination part
			"order": [[0, "desc"]],
			"pageLength": 25,
			"searching": true,  // Ensure search functionality is enabled
			"paging": true,      // Explicitly enable pagination

		});

		$('.rep-datatable-default').dataTable({
			dom: '<"row"<"col-lg-6"l><"col-lg-6"f>><"table-responsive"t>',
			"order": [[0, "desc"]],
			"pageLength": -1,  // Show all rows
			"searching": false  // Ensure search functionality is enabled
		});

		// $('#cust-datatable-default').dataTable({
		// 	"order": [[0, "desc"]],
		// 	"pageLength": 25,  // Show all rows
		// });
	};

	

	$(function() {
		datatableInit();
	});

}).apply(this, [jQuery]);