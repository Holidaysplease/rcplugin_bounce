<?php

/**
 * Bounce
 *
 * Allow to redirect (a.k.a. "bounce") mail messages to other
 * Ticket #1485774 http://trac.roundcube.net/ticket/1485774
 *
 * @version 1.1
 * @author Denis Sobolev
 */
class bounce extends rcube_plugin
{
  public $task = 'mail';
  private $email_format_error, $recipient_count;

  function init()
  {
    $rcmail = rcmail::get_instance();

    $this->register_action('plugin.bounce', array($this, 'request_action'));

    if ($rcmail->task == 'mail' && ($rcmail->action == '' || $rcmail->action == 'show')) {
      $skin_path = $this->local_skin_path();

      $this->include_script('bounce.js');
      $this->add_texts('localization', true);
      $this->add_button(
        array(
            'type'  => 'link',
            'label' => 'bouncemessage',
            'command' => 'plugin.bounce.box',
            'title'   => 'bouncemessage',
            'domain'  =>  $this->ID,
            'class' => 'bouncelink active',
            'wrapper' => 'li',
        ),
        'forwardmenu');

      $this->add_hook('render_page', array($this, 'render_box'));

    }
  }

  function request_action() {
    $this->add_texts('localization');
    $msg_uid = get_input_value('_uid', RCUBE_INPUT_POST);
    $mbox = get_input_value('_mbox', RCUBE_INPUT_POST);

    $rcmail = rcmail::get_instance();

    $this->email_format_error = NULL;
    $this->recipient_count = 0;

    $message_charset = $rcmail->output->get_charset();
    $mailto = $this->rcmail_email_input_format(get_input_value('_to', RCUBE_INPUT_POST, TRUE, $message_charset), true);

    if ($this->email_format_error) {
      $rcmail->output->show_message('emailformaterror', 'error', array('email' => $this->email_format_error));
      $rcmail->output->send('iframe');
      exit;
    }

    $a_recipients = array ();
    $a_recipients['To'] = $mailto;

    $rcmail->storage->set_folder($mbox);

    $headers = $rcmail->storage->get_raw_headers($msg_uid);

    $msg_body = $rcmail->storage->get_raw_body($msg_uid);

    // split mail at the first empty line, everything above is header, everything below message
    $body = preg_split('/^[\s]+$/sm', $msg_body, 2)[1];

    if (!is_object($rcmail->smtp))
      $rcmail->smtp_init(true);

    $sent = $rcmail->smtp->send_mail('', $a_recipients, $headers, $body);
    $smtp_response = $rcmail->smtp->get_response();
    $smtp_error = $rcmail->smtp->get_error();

    if (!$sent) {
      if ($smtp_error)
        $rcmail->output->show_message($smtp_error['label'], 'error', $smtp_error['vars']);
      else
        $rcmail->output->show_message('sendingfailed', 'error');
      $rcmail->output->send();
    } else {
      if ($rcmail->config->get('smtp_log')) {
        $log_entry = sprintf("User %s [%s]; Message for %s; %s",
          $rcmail->user->get_username(),
          $_SERVER['REMOTE_ADDR'],
          $mailto,
          "SMTP status: ".join("\n", $smtp_response));
          write_log('sendmail', $log_entry);
      }
      $rcmail->output->command('display_message', $this->gettext('messagebounced'), 'confirmation');
      $rcmail->output->send();
    }
  }

  function render_box($p) {
    $this->add_texts('localization');
    $rcmail = rcmail::get_instance();

    if (!$attrib['id']) {
      $attrib['id'] = 'bounce-box';
      $attrib['class'] = 'popupmenu';
    }

    $button = new html_inputfield(array('type' => 'button'));
    $submit = new html_inputfield(array('type' => 'submit'));
    $table = new html_table(array('cols' => 2, 'id' => 'form'));

    $table->add('title', html::label('_to', rcube::Q($rcmail->gettext('to'))));
    $table->add('editfield', html::tag('textarea', array('spellcheck' =>'false', 'id' => '_to', 'name' => '_to', 'cols' => '50', 'rows'=> '2', 'tabindex' => '2', 'class' => 'editfield', 'onclick' => 'select_field(this)')));

    $table->add(null,null);

    $target_url = $_SERVER['REQUEST_URI'];

    $rcmail->output->add_footer(html::div($attrib,
      $rcmail->output->form_tag(array('name' => 'bounceform', 'method' => 'post', 'action' => './', 'enctype' => 'multipart/form-data'),
        html::tag('input', array('type' => "hidden", 'name' => '_action', 'value' => 'bounce')) .
        html::div('bounce-title', rcube::Q($this->gettext('bouncemessage'))) .
        html::div('bounce-body',
          $table->show() .
          html::div('buttons',
            $button->show($rcmail->gettext('close'), array('class' => 'button', 'onclick' => "$('#$attrib[id]').hide()")) . ' ' .
            $button->show(rcube::Q($this->gettext('bounce')), array('class' => 'button mainaction',
              'onclick' => JS_OBJECT_NAME . ".command('plugin.bounce.send', this.bounceform)"))
          )
        )
      )
    ));
    $rcmail->output->add_label('norecipientwarning');
    $rcmail->output->add_gui_object('bouncebox', $attrib['id']);
    $rcmail->output->add_gui_object('bounceform', 'bounceform');

    $this->include_stylesheet('bounce.css');
    $rcmail->output->set_env('autocomplete_min_length', $rcmail->config->get('autocomplete_min_length'));
    $rcmail->output->add_gui_object('messageform', 'bounceform');
  }


  /*
   * Used modified function from steps/mail/sendmail.inc
   */
  private function rcmail_email_input_format($mailto, $count=false, $check=true) {

    $regexp = array('/[,;]\s*[\r\n]+/', '/[\r\n]+/', '/[,;]\s*$/m', '/;/', '/(\S{1})(<\S+@\S+>)/U');
    $replace = array(', ', ', ', '', ',', '\\1 \\2');

    // replace new lines and strip ending ', ', make address input more valid
    $mailto = trim(preg_replace($regexp, $replace, $mailto));

    $result = array();
    $items = rcube_explode_quoted_string(',', $mailto);

    foreach($items as $item) {
      $item = trim($item);
      // address in brackets without name (do nothing)
      if (preg_match('/^<\S+@\S+>$/', $item)) {
        $item = idn_to_ascii($item);
        $result[] = $item;
      // address without brackets and without name (add brackets)
      } else if (preg_match('/^\S+@\S+$/', $item)) {
        $item = idn_to_ascii($item);
        $result[] = '<'.$item.'>';
      // address with name (handle name)
      } else if (preg_match('/\S+@\S+>*$/', $item, $matches)) {
        $address = $matches[0];
        $name = str_replace($address, '', $item);
        $name = trim($name);
        if ($name && ($name[0] != '"' || $name[strlen($name)-1] != '"')
            && preg_match('/[\(\)\<\>\\\.\[\]@,;:"]/', $name)) {
            $name = '"'.addcslashes($name, '"').'"';
        }
        $address = idn_to_ascii($address);
        if (!preg_match('/^<\S+@\S+>$/', $address))
          $address = '<'.$address.'>';

        $result[] = $name.' '.$address;
        $item = $address;
      } else if (trim($item)) {
        continue;
      }

      // check address format
      $item = trim($item, '<>');
      if ($item && $check && !check_email($item)) {
        $this->email_format_error = $item;
        return;
      }
    }

    if ($count) {
      $this->recipient_count += count($result);
    }

    return implode(', ', $result);
  }
}
