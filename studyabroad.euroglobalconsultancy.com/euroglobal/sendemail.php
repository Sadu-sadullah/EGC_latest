<?php
use PHPMailer\PHPMailer\PHPMailer;


//process_data.php

if(isset($_POST["first_email"]))
{
   
 $first_name = '';
 $email = '';
 $phone = '';
 $first_comment='';
 $country='';

 $first_name_error = '';
 $email_error = '';
 $phone_error='';

 if(empty($_POST["first_name"]))
 {
  $first_name_error = 'Name is required';
 }
 else
 {
  $first_name = $_POST["first_name"];
 }


  $first_comment = $_POST["first_comment"];


 
 if(empty($_POST["first_email"]))
 {
  $email_error = 'Email is required';
 }
 else
 {
  if(!filter_var($_POST["first_email"], FILTER_VALIDATE_EMAIL))
  {
   $email_error = 'Invalid Email';
  }
  else
  {
   $email = $_POST["first_email"];
  }
 }

 if(empty($_POST["phone"]))
 {
  $phone_error = 'Phone is required';
 }
 else
 {
  $phone = $_POST["phone"];
 }
$country = $_POST["country_name"];




 if($first_name_error == '' && $email_error == ''  || $phone_error == '')
 {
            // $location='ddfdfd';
            $email_from = $_POST['first_email'];// required


            $comment =$_POST['first_comment'];
            $name =$_POST['first_name'];
            $phone=$_POST['phone'];
            $address=$_POST['address'];
            $country = $_POST["country_name"];
        require_once "PHPMailer/PHPMailer.php";
        require_once "PHPMailer/SMTP.php";
        require_once "PHPMailer/Exception.php";
        $mail = new PHPMailer(true);



        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        //Server settings
        //$mail->SMTPDebug = 0;                                       // Enable verbose debug output
        $mail->isSMTP();                                            // Set mailer to use SMTP
        $mail->Host       = 'mail.euroglobalconsultancy.com';  // Specify main and backup SMTP servers
        $mail->SMTPAuth   = true;                                   // Enable SMTP authentication
        $mail->Username   = 'enquiry@euroglobalconsultancy.com';                     // SMTP username
        $mail->Password   = 'euro@2023';                               // SMTP password
        $mail->SMTPSecure = 'ssl';                                  // Enable TLS encryption, [ICODE]ssl[/ICODE] also accepted
        $mail->Port       = 465;                                    // TCP port to connect to
        $mail->SMTPDebug  = 0; 

        //Recipients
        $mail->setFrom('enquiry@euroglobalconsultancy.com', 'Mailer');
        $mail->addAddress('enquiry@euroglobalconsultancy.com');     // Add a recipient TO//



        // // Attachments
        // $mail->addAttachment('/home/cpanelusername/attachment.txt');         // Add attachments
        // $mail->addAttachment('/home/cpanelusername/image.jpg', 'new.jpg');    // Optional name

        // Content
          $email_message = "New Enquiry Details from landing page.\n\n"."<br/>";

        function clean_string($string) {
            $bad = array("content-type","bcc:","to:","cc:","href");
            return str_replace($bad,"",$string);
          }
        $mail->isHTML(true);                                  // Set email format to HTML
        $mail->Subject = 'Euro Global Consultancy Client is Here (landing)....';
        $email_message .= "Hello,"."<br/>";
        // $email_message .= "New Appointment"."<br/>";

        $email_message .= "From: ".clean_string($email_from)."<br/>";
        $email_message .= "Name: ".clean_string($name)."<br/>";
        $email_message .= "Phone: ".clean_string($phone)."<br/>";
        $email_message .= "Country name:: ".clean_string($country)."<br/>";
        $email_message .= "Message: ".clean_string($comment)."<br/>";
        $mail->Body    = $email_message;

        // $mail->send();
        if ($mail->send())
        {
          $data=array('message'=>'success');
          echo json_encode($data);


        }else{

          $data=array('message'=>'No send EMail');

        }

     
   
 }
 else
 {
    $data = array(
    'first_name_error' => $first_name_error,
    'last_name_error' => $last_name_error,
    'email_error'  => $email_error,
    );
 }

 echo json_encode($data);
}

?>