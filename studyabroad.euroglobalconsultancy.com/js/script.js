!function(e){"use strict";function a(){if(e(".main-header").length){var a=e(window).scrollTop(),t=e(".header-style-one"),s=e(".scroll-to-top"),n=e(".main-header .sticky-header");a>100?(n.addClass("fixed-header animated slideInDown"),s.fadeIn(300)):(n.removeClass("fixed-header animated slideInDown"),s.fadeOut(300)),a>1?t.addClass("fixed-header"):t.removeClass("fixed-header")}}if(a(),e(".main-header li.dropdown ul").length&&e(".main-header .navigation li.dropdown").append('<div class="dropdown-btn"><i class="fa fa-angle-down"></i></div>'),e(".mobile-menu").length){var t=e(".main-header .main-menu .navigation").html();e(".mobile-menu .navigation").append(t),e(".sticky-header .navigation").append(t),e(".mobile-menu .close-btn").on("click",function(){e("body").removeClass("mobile-menu-visible")}),e(".mobile-menu li.dropdown .dropdown-btn").on("click",function(){e(this).prev("ul").slideToggle(500),e(this).toggleClass("active")}),e(".mobile-nav-toggler").on("click",function(){e("body").addClass("mobile-menu-visible")}),e(".mobile-menu .menu-backdrop, .mobile-menu .close-btn").on("click",function(){e("body").removeClass("mobile-menu-visible")})}e(".search-btn").length&&(e(".search-btn").on("click",function(){e(".main-header").addClass("moblie-search-active")}),e(".close-search, .search-back-drop").on("click",function(){e(".main-header").removeClass("moblie-search-active")})),e(".banner-carousel").length&&e(".banner-carousel").owlCarousel({animateOut:"fadeOut",animateIn:"fadeIn",loop:!0,margin:0,nav:!0,smartSpeed:1e3,autoHeight:!0,autoplay:!0,autoplayTimeout:4e3,navText:['<span class="fa fa-long-arrow-alt-left"></span>','<span class="fa fa-long-arrow-alt-right"></span>'],responsive:{0:{items:1},600:{items:1},1024:{items:1}}}),e(".countries-carousel").length&&e(".countries-carousel").owlCarousel({loop:!0,margin:0,nav:!1,smartSpeed:100,autoHeight:!0,autoplay:!0,autoplayTimeout:2e3,navText:['<span class="fa fa-long-arrow-alt-left"></span>','<span class="fa fa-long-arrow-alt-right"></span>'],responsive:{0:{items:1},600:{items:2},991:{items:3},1200:{items:4},1400:{items:5}}}),e(".gallery-carousel").length&&e(".gallery-carousel").owlCarousel({loop:!0,margin:30,nav:!0,smartSpeed:100,autoplay:!0,navText:['<span class="flaticon-left"></span>','<span class="flaticon-right"></span>'],responsive:{0:{items:1},600:{items:2},1023:{items:3},1200:{items:4}}}),e(".gallery-carousel-two").length&&e(".gallery-carousel-two").owlCarousel({loop:!0,margin:0,nav:!1,smartSpeed:100,autoplay:!0,navText:['<span class="flaticon-left"></span>','<span class="flaticon-right"></span>'],responsive:{0:{items:1},768:{items:2},1200:{items:3},1400:{items:4}}}),e(".training-carousel").length&&e(".training-carousel").owlCarousel({loop:!0,margin:30,nav:!0,smartSpeed:100,autoplay:!0,autoplayTimeout:2e3,navText:['<span class="flaticon-left"></span>','<span class="flaticon-right"></span>'],responsive:{0:{items:1},600:{items:2},768:{items:2},1023:{items:3},1200:{items:4}}}),e(".training-carousel1").length&&e(".training-carousel1").owlCarousel({loop:!0,margin:30,nav:!0,smartSpeed:100,autoplay:0,autoplayTimeout:2e3,navText:['<span class="flaticon-left"></span>','<span class="flaticon-right"></span>'],responsive:{0:{items:1},600:{items:2},768:{items:2},1023:{items:3},1200:{items:3}}}),e(".testimonial-carousel").length&&e(".testimonial-carousel").owlCarousel({loop:!0,margin:0,nav:!1,items:1,smartSpeed:100,autoplay:5e3,navText:['<span class="flaticon-left-chevron"></span>','<span class="flaticon-right-chevron"></span>'],responsive:{0:{items:1},768:{items:2},1199:{items:3}}}),e(".testimonial-carousel-two").length&&e(".testimonial-carousel-two").owlCarousel({loop:!0,margin:0,nav:!1,items:1,smartSpeed:100,autoplay:5e3,navText:['<span class="flaticon-left-chevron"></span>','<span class="flaticon-right-chevron"></span>'],responsive:{0:{items:1},991:{items:2}}}),e(".clients-carousel").length&&e(".clients-carousel").owlCarousel({loop:!0,margin:10,nav:!1,smartSpeed:100,autoplay:!0,navText:['<span class="fa fa-angle-left"></span>','<span class="fa fa-angle-right"></span>'],responsive:{0:{items:1},480:{items:2},600:{items:3},768:{items:4},1023:{items:4}}}),e(".clients-carousel4").length&&e(".clients-carousel4").owlCarousel({loop:!0,margin:200,nav:!1,smartSpeed:100,autoplay:!0,navText:['<span class="fa fa-angle-left"></span>','<span class="fa fa-angle-right"></span>'],responsive:{0:{items:1},480:{items:2},600:{items:3},768:{items:3},1023:{items:3}}}),e(".clients-carousel1").length&&e(".clients-carousel1").owlCarousel({loop:!0,margin:10,nav:!1,smartSpeed:100,autoplay:!0,autoplayTimeout:2e3,navText:['<span class="fa fa-angle-left"></span>','<span class="fa fa-angle-right"></span>'],responsive:{0:{items:1},480:{items:2},600:{items:3},768:{items:5},1023:{items:5}}}),e(".clients-carousel-two").length&&e(".clients-carousel-two").owlCarousel({loop:!0,margin:0,nav:!1,smartSpeed:100,autoplay:!0,navText:['<span class="fa fa-angle-left"></span>','<span class="fa fa-angle-right"></span>'],responsive:{0:{items:1},480:{items:2},600:{items:3},991:{items:4},1200:{items:5},1400:{items:6}}}),e(".service-block-two").length&&(e(".service-block-two .inner-box").on("mouseenter",function(a){a.preventDefault(),e(this).find(".text").slideDown(300)}),e(".service-block-two .inner-box").on("mouseleave",function(a){a.preventDefault(),e(this).find(".text").slideUp(300)})),e(".acc-btn").click(function(a){a.preventDefault();var t=e(this);t.addClass("active"),t.next().hasClass("active-block")?(t.next().removeClass("active-block"),t.next().slideUp(300),t.hasClass("active"),t.removeClass("active")):(t.parent().parent().find(".acc-content").removeClass("active-block"),t.parent().parent().find(".acc-content").slideUp(300),t.next().toggleClass("active-block"),t.next().slideToggle(300))}),e(".product-details .bxslider").length&&e(".product-details .bxslider").bxSlider({nextSelector:".product-details #slider-next",prevSelector:".product-details #slider-prev",nextText:'<i class="fa fa-angle-right"></i>',prevText:'<i class="fa fa-angle-left"></i>',mode:"fade",auto:"true",speed:"700",pagerCustom:".product-details .slider-pager .thumb-box"}),e(".tabs-box").length&&e(".tabs-box .tab-buttons .tab-btn").on("click",function(a){a.preventDefault();var t=e(e(this).attr("data-tab"));if(e(t).is(":visible"))return!1;t.parents(".tabs-box").find(".tab-buttons").find(".tab-btn").removeClass("active-btn"),e(this).addClass("active-btn"),t.parents(".tabs-box").find(".tabs-content").find(".tab").fadeOut(0),t.parents(".tabs-box").find(".tabs-content").find(".tab").removeClass("active-tab animated fadeIn"),e(t).fadeIn(300),e(t).addClass("active-tab animated fadeIn")}),e(".scroll-to-target").length&&e(".scroll-to-target").on("click",function(){var a=e(this).attr("data-target");e("html, body").animate({scrollTop:e(a).offset().top},1500)}),e(".time-countdown").length&&e(".time-countdown").each(function(){var a=e(this),t=e(this).data("countdown");a.countdown(t,function(a){e(this).html(a.strftime('<div class="counter-column"><span class="count">%D</span><span class="title">Days</span></div> <div class="counter-column"><span class="count">%H</span><span class="title">Hours</span></div>  <div class="counter-column"><span class="count">%M</span><span class="title">Minutes</span></div>  <div class="counter-column"><span class="count">%S</span><span class="title">Seconds</span></div>'))})}),e(".quantity-box .add").on("click",function(){999>e(this).prev().val()&&e(this).prev().val(+e(this).prev().val()+1)}),e(".quantity-box .sub").on("click",function(){e(this).next().val()>1&&e(this).next().val()>1&&e(this).next().val(+e(this).next().val()-1)}),e(".price-range-slider").length&&(e(".price-range-slider").slider({range:!0,min:10,max:99,values:[10,60],slide:function(a,t){e("input.property-amount").val(t.values[0]+" - "+t.values[1])}}),e("input.property-amount").val(e(".price-range-slider").slider("values",0)+" - $"+e(".price-range-slider").slider("values",1))),e(".filter-list").length&&e(".filter-list").mixItUp({}),e(window).on("scroll",function(){a()}),e(window).on("load",function(){e(".preloader").length&&e(".preloader").delay(200).fadeOut(500)})}(window.jQuery),$(".count-box").length&&$(".count-box").appear(function(){var e=$(this),a=e.find(".count-text").attr("data-stop"),t=parseInt(e.find(".count-text").attr("data-speed"),10);e.hasClass("counted")||(e.addClass("counted"),$({countNum:e.find(".count-text").text()}).animate({countNum:a},{duration:t,easing:"linear",step:function(){e.find(".count-text").text(Math.floor(this.countNum))},complete:function(){e.find(".count-text").text(this.countNum)}}))},{accY:0}),$(".count-bar").length&&$(".count-bar").appear(function(){var e=$(this),a=e.data("percent");$(e).css("width",a).addClass("counted")},{accY:-50});



$(document).ready(function () {
    $('#mail11').on('submit', function (event) {
        event.preventDefault();
        $.ajax({
            url: "sendemail11.php",
            method: "POST",
            data: $(this).serialize(),
            beforeSend: function () {
                $('#register').attr('disabled', 'disabled');
                $('#mail11')[0].reset();
            },
            success: function (data) {
                $('#mail11')[0].reset();
                alert('Form Sent Successfully');
            }
        })
    });
});

$(document).ready(function () {
    $('#mail12').on('submit', function (event) {
        event.preventDefault();
        $.ajax({
            url: "sendemail12.php",
            method: "POST",
            data: $(this).serialize(),
            beforeSend: function () {
                $('#register').attr('disabled', 'disabled');
                $('#mail12')[0].reset();
            },
            success: function (data) {
                // exit();
                $('#mail12')[0].reset();
                alert('Form Sent Successfully');
            }
        })
    });
});

// $(document).ready(function () {
//     $('#mail12').on('submit', function (event) {
//         event.preventDefault();
//         if(grecaptcha.getResponse()){
//         $.ajax({
//             url: "sendemail12.php",
//             method: "POST",
//             data: $(this).serialize(),
//             beforeSend: function () {
//                 $('#register').attr('disabled', 'disabled');
//                 $('#mail12')[0].reset();
//             },
//             success: function (data) {
//                 $('#mail12')[0].reset();
//                 grecaptcha.reset();
//                 alert('Form Successfully');
//             }
//         })
//         }else{
            
//              document.getElementById('g-recaptcha').innerHTML = 'Error In Captcha';
//              grecaptcha.reset();
//         }
//     });
// });