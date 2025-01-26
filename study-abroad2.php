<?php
$config = include '../config.php';
// Database Connection
$dsn = "mysql:host=localhost;dbname=euro_universities;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

try {
    $pdo = new PDO($dsn, $username, $password);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Fetch Programs
$query = "SELECT * FROM programs";
$stmt = $pdo->query($query);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-P0RM2XGQ2R"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', 'G-P0RM2XGQ2R');
    </script>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="theme-color" content="#061948">
    <meta name="description"
        content="Fulfill your study abroad dreams with us. We offer expert guidance, personalized support, and access to prestigious universities all over the world.">
    <meta property="og:title" content="Study Abroad Consultancy: Expert Guidance & Top Universities">
    <meta property="og:description"
        content="Fulfill your study abroad dreams with us. We offer expert guidance, personalized support, and access to prestigious universities all over the world.">
    <title>Study Abroad Consultancy</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/responsive.css">
</head>

<body>
    <div class="main-page-wrapper">
        <section class="training-section course_section">
            <div class="auto-container">
                <h3 class="title1">Find Your Dream Program</h3>
                <div class="filters">
                    <div class="ui-group">
                        <h3>Find by Level of Study</h3>
                        <div class="button-group js-radio-button-group" data-filter-group="level">
                            <button class="button is-checked" data-filter=".bachelors">Bachelors</button>
                            <button class="button" data-filter=".masters">Masters</button>
                        </div>
                    </div>

                    <div class="ui-group">
                        <h3>Find by Country</h3>
                        <div class="button-group js-radio-button-group" data-filter-group="country">
                            <button class="button is-checked" data-filter="*">All</button>
                            <button class="button" data-filter=".italy">Italy</button>
                            <button class="button" data-filter=".hungary">Hungary</button>
                            <button class="button" data-filter=".latvia">Latvia</button>
                            <button class="button" data-filter=".slovakia">Slovakia</button>
                            <button class="button" data-filter=".portugal">Portugal</button>
                            <button class="button" data-filter=".czech">Czech Republic</button>
                            <button class="button" data-filter=".lithuania">Lithuania</button>
                            <button class="button" data-filter=".malta">Malta</button>
                        </div>
                    </div>

                    <div class="ui-group">
                        <h3>Find by Domain</h3>
                        <div class="button-group js-radio-button-group" data-filter-group="domain">
                            <button class="button is-checked" data-filter="*">All</button>
                            <button class="button" data-filter=".engineering">Engineering</button>
                            <button class="button" data-filter=".arts">Arts &amp; Science</button>
                            <button class="button" data-filter=".management">Management</button>
                            <button class="button" data-filter=".health">Health &amp; Medicine</button>
                            <button class="button" data-filter=".building">Building &amp; Architecture</button>
                        </div>
                    </div>
                </div>

                <div class="row grid mt-5">
                    <?php foreach ($programs as $program): ?>
                        <div
                            class="service-block col-lg-4 color-shape <?= htmlspecialchars($program['level_of_study']) ?> <?= htmlspecialchars($program['country']) ?> <?= htmlspecialchars($program['domain']) ?>">
                            <div class="inner-box">
                                <div class="content-box">
                                    <h6 class="lab"><?= htmlspecialchars($program['program_lab']) ?></h6>
                                    <h5 class="title"><a
                                            href="contact.html"><?= htmlspecialchars($program['program_title']) ?></a>
                                    </h5>
                                    <div class="course_tags">
                                        <div class="course_tag">
                                            <div class="icontag">
                                                <i class="fa fa-university"></i>
                                            </div>
                                            <div class="icon_text">
                                                <h6>University</h6>
                                                <p><?= htmlspecialchars($program['university']) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tags py-2">
                                        <?php foreach (explode(',', $program['tags']) as $tag): ?>
                                            <span class="cc"><?= htmlspecialchars(trim($tag)) ?></span>
                                        <?php endforeach; ?>
                                        <span class="cc2 cc"><a style="color: inherit;"
                                                href="contact.html">Know More</a></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>

    <script src="vendor/jquery.2.2.3.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.isotope/3.0.6/isotope.pkgd.min.js"></script>
    <script>
        // Initialize Isotope grid
        var $grid = $('.grid').isotope({
            itemSelector: '.color-shape',
            layoutMode: 'fitRows',
            filter: '.bachelors' // Default filter when the page loads
        });

        // Store filter for each group
        var filters = {};
        $('.filters').on('click', '.button', function (event) {
            var $button = $(event.currentTarget);
            var filterGroup = $button.closest('.button-group').data('filter-group');
            filters[filterGroup] = $button.attr('data-filter');
            var filterValue = concatValues(filters);
            $grid.isotope({ filter: filterValue });
        });

        // Change is-checked class on buttons
        $('.button-group').each(function (i, buttonGroup) {
            var $buttonGroup = $(buttonGroup);
            $buttonGroup.on('click', 'button', function (event) {
                $buttonGroup.find('.is-checked').removeClass('is-checked');
                var $button = $(event.currentTarget);
                $button.addClass('is-checked');
            });
        });

        // Concatenate filter values
        function concatValues(obj) {
            var value = '';
            for (var prop in obj) {
                value += obj[prop];
            }
            return value;
        }
    </script>
</body>
</html>
