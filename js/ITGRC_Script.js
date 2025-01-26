$(document).ready(function() {
    // Handle tab button clicks
    $('.tab-btn').click(function() {
        var tabId = $(this).data('tab');

        // Hide all tabs
        $('.tab').hide();

        // Show the active tab
        $(tabId).show();

        // Update active button class
        $('.tab-btn').removeClass('active-btn');
        $(this).addClass('active-btn');
    });

    // Initialize the first tab as active
    $('.tab-btn:first').trigger('click');
});

const columnDivs = document.querySelectorAll('.col-xl-4.col-lg-4.col-md-6.col-sm-12.column, .col-xl-6.col-lg-6.col-md-6.col-sm-12.column');

    columnDivs.forEach(columnDiv => {
    columnDiv.addEventListener('mouseover', () => {
        columnDiv.querySelector('.single-item').style.backgroundColor = '#093664';
        columnDiv.querySelectorAll('h6, .icon-box i').forEach(element => {
        element.style.color = 'white';
        });
    });

    columnDiv.addEventListener('mouseout', () => {
        columnDiv.querySelector('.single-item').style.backgroundColor = '';
        columnDiv.querySelectorAll('h6, .icon-box i').forEach(element => {
        element.style.color = '';
        });
    });
    });