(function($) {
    "use strict";

    
    $(document).ready(function() {

        /*
        |----------------------------------------------------------------------------
        | STICKY NAVBAR
        |----------------------------------------------------------------------------
        */
        $(window).scroll(function () {
            if ($(window).scrollTop() >= 40) {
                $('.navbar').addClass('fixed-header');
            }
            else {
                $('.navbar').removeClass('fixed-header');
            }
        });
       

        /* -------------------------------------------------------------
            Feature-item-slider
        ------------------------------------------------------------- */
        if ($('.testimonial-slider').length){
            $('.testimonial-slider').owlCarousel({
                items: 1,
                loop: true,
                autoplay: true,
                autoplayTimeout: 5000,
                nav: true,
                dots: false,
                navText: ['<i class="fa fa-angle-left"></i>', '<i class="fa fa-angle-right"></i>'],
            });
        }

        /* -------------------------------------------------------------
            Feature-item-slider
        ------------------------------------------------------------- */
        if ($('.testimonial-slider-2').length){
            $('.testimonial-slider-2').owlCarousel({
                items: 3,
                loop: true,
                autoplay: true,
                autoplayTimeout: 5000,
                nav: false,
                dots: false,
            });
        }

        /* -------------------------------------------------------------
            Feature-item-slider
        ------------------------------------------------------------- */
        if ($('.partner-slider').length){
            $('.partner-slider').owlCarousel({
                loop: true,
                autoplay: true,
                autoplayTimeout: 5000,
                nav: false,
                dots: false,
                responsive:{
                    0:{
                        items:1
                    },
                    768:{
                        items: 3
                    },
                    1024:{
                        items: 5
                    }
                }
            });
        }

        /* -------------------------------------------------------------
            Breaking-news-slider
        ------------------------------------------------------------- */
        if ($('.breaking-news-slider').length){
          $('.breaking-news-slider').owlCarousel({
            items: 1,
            loop: true,
            autoplay: true,
            nav: false,
            dots: false,
            responsive:{
              768:{
                  items: 3
              },
              1024:{
                  items: 4
              },
              1025:{
                  items: 6
              },
              1600:{
                  items: 7
              }
            }
          });
        }


        /* -------------------------------------------------------------
            Fact Counter
        ------------------------------------------------------------- */
        if ( $('.fact-count').length){
            $('.fact-count').counterUp({
                delay: 10,
                time: 1000
            });
        }





        /* -------------------------------------------------------------
            pricing tab Map
        ------------------------------------------------------------- */
        if ( $('.pricing-tabs').length){ 
          $( ".pricing-tabs" ).tabs();
        }
        /*
        |----------------------------------------------------------------------------
        | Google Map
        |----------------------------------------------------------------------------
        */
    

        /*
        |----------------------------------------------------------------------------
        | Ajax Mailchimp
        |----------------------------------------------------------------------------
        */
        $(document).ready(function () {
            var $form = $('#mc-embedded-subscribe-form')
            if ($form.length > 0) {
                $('form input[type="submit"]').on('click', function (event) {
                    if (event) event.preventDefault()
                    register($form)
                })
            }
        })

        function register($form) {
        $('#mc-embedded-subscribe').val('Sending...');
        $.ajax({
            type: $form.attr('method'),
            url: $form.attr('action'),
            data: $form.serialize(),
            cache: false,
            dataType: 'json',
            contentType: 'application/json; charset=utf-8',
            error: function (err) { alert('Could not connect to the registration server. Please try again later.') },
            success: function (data) {
                $('#mc-embedded-subscribe').val('subscribe')
                if (data.result === 'success') {
                    // Yeahhhh Success
                    console.log(data.msg)
                    $('#mce-EMAIL').css('borderColor', '#ffffff')
                    $('#subscribe-result').css('color', 'rgb(53, 114, 210)')
                    $('#subscribe-result').html('<p>Thank you for subscribing. We have sent you a confirmation email.</p>')
                    $('#mce-EMAIL').val('')
                } else {
                    // Something went wrong, do something to notify the user.
                    console.log(data.msg)
                    $('#mce-EMAIL').css('borderColor', '#ff8282')
                    $('#subscribe-result').css('color', '#ff8282')
                    $('#subscribe-result').html('<p>' + data.msg.substring(4) + '</p>')
                }
            }
        })
    };


    /* -------------------------------------------------------------
      marque js
      ------------------------------------------------------------- */
    var off = 10,
        l = off,
        $As = $('.marquee li'), 
        speed = 2,
        stack = [],
        pause = false;

    $.each($As, function(){
      var W = $(this).css({
        left: l
      }).width()+off;
      l+=W; 
      stack.push($(this));
    });

    var tick = setInterval(function(){
      if(!pause){
        $.each($As, function(){
          var ml = parseFloat($(this).css('left'))-speed;
          $(this).css({
            left: ml
          });
          if((ml+$(this).width()) < 0){
            var $first = stack.shift(),
                $last = stack[stack.length-1];
            $(this).css({
              left: (parseFloat($last.css('left'))+parseFloat($last.width()))+off
            });
            stack.push($first);
          }
        });
      }
    }, 1000/25);

    $('.marquee').on('mouseover', function(){
      pause = true;
    }).on('mouseout', function(){
      pause = false;
    });

    /* -------------------------------------------------------------
          Scroll To Top
      ------------------------------------------------------------- */

      

    });
})(jQuery);