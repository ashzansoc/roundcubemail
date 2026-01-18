<?php

class ai_config extends rcube_plugin
{
    public $task = 'ai_config';
    private $selected_emails = [];

    function init()
    {
        $this->register_task('ai_config');
        $this->register_action('index', [$this, 'action_index']);
        $this->register_action('process', [$this, 'action_process']);
        $this->register_action('llm_window', [$this, 'action_llm_window']);

        $this->add_button([
            'type' => 'link',
            'label' => 'AI Config',
            'name' => 'ai_config',
            'class' => 'button-ai-config',
            'href' => rcmail::get_instance()->url(['_task' => 'ai_config']),
            'innerhtml' => '<span class="inner"><span class="icon"></span><span class="label">AI Config</span></span>',
        ], 'taskbar');
    }

    function action_index()
    {
        $rcmail = rcmail::get_instance();
        $rcmail->output->set_pagetitle('AI Configuration');
        $rcmail->output->send('ai_config.main');
    }

    function action_process()
    {
        $rcmail = rcmail::get_instance();
        $storage = $rcmail->get_storage();

        $sort_by = rcube_utils::get_input_value('sort_by', rcube_utils::INPUT_POST);
        $subject_filter = rcube_utils::get_input_value('filter_subject', rcube_utils::INPUT_POST);
        $from_filter = rcube_utils::get_input_value('filter_from', rcube_utils::INPUT_POST);

        $search_conditions = [];
        // Gmail/IMAP usually treats SUBJECT search as substring case-insensitive
        if (!empty($subject_filter)) {
            $search_conditions[] = 'SUBJECT "' . addslashes($subject_filter) . '"';
        }
        if (!empty($from_filter)) {
            $search_conditions[] = 'FROM "' . addslashes($from_filter) . '"';
        }

        $search = empty($search_conditions) ? 'ALL' : implode(' ', $search_conditions);

        $sort_col = 'DATE';
        if ($sort_by == 'subject')
            $sort_col = 'SUBJECT';
        if ($sort_by == 'from')
            $sort_col = 'FROM';

        // Force descending order (newest first)
        $sort_query = $sort_col . ' DESC';

        $search_result = $storage->search('INBOX', $search, $sort_query);

        $mids = [];
        if (is_object($search_result) && method_exists($search_result, 'get')) {
            $mids = $search_result->get();
        } elseif (is_array($search_result)) {
            $mids = $search_result;
        }

        // Force PHP-side sorting to ensure Newest First (Desc) for Date
        if ($sort_col == 'DATE') {
            rsort($mids, SORT_NUMERIC);
        } elseif ($sort_query && strpos($sort_query, 'DESC') !== false) {
            $mids = array_reverse($mids); // Just in case IMAP returned ASC
        }

        // Limit to 20 to give better results
        $mids = array_slice($mids, 0, 20);

        $consolidated_data = [];

        foreach ($mids as $uid) {
            $message = new rcube_message($uid, 'INBOX');

            $sender = $message->sender;
            $sender_name = $sender['name'] ?? $sender['string'] ?? 'Unknown';

            // Robust Date Parsing
            $date_raw = $message->get_header('date');
            $timestamp = rcube_utils::strtotime($date_raw);
            if (!$timestamp && !empty($message->headers->internaldate)) {
                $timestamp = rcube_utils::strtotime($message->headers->internaldate);
            }
            if (!$timestamp && $date_raw) {
                $timestamp = strtotime($date_raw);
            }

            if ($timestamp) {
                $date_display = $rcmail->format_date($timestamp);
            } else {
                $date_display = $date_raw ?: 'Unknown Date';
            }

            $has_attachments = !empty($message->attachments);
            $plain_body = strip_tags($message->first_text_part() ?? '');

            // Attachment extraction
            $attachment_text = "";
            if (!empty($message->attachments)) {
                foreach ($message->attachments as $attach) {
                    if (empty($attach->mime_id))
                        continue;

                    // Simple check for text-based types to extract content
                    if (
                        strpos($attach->mimetype, 'text/') === 0 ||
                        $attach->mimetype == 'application/json' ||
                        $attach->mimetype == 'application/csv'
                    ) {

                        $content = $message->get_part_body($attach->mime_id);
                        if ($content) {
                            $attachment_text .= "\n[Attachment: " . ($attach->filename ?? 'unnamed') . "]\n";
                            $attachment_text .= substr($content, 0, 10000) . "\n";
                        }
                    } else {
                        // binary/other
                        $attachment_text .= "\n[Attachment: " . ($attach->filename ?? 'unnamed') . " - Type: " . $attach->mimetype . " (Start of content not extracted)]\n";
                    }
                }
            }

            // Combine for AI context (increase limit)
            $full_body = substr($plain_body, 0, 10000);
            if (!empty($attachment_text)) {
                $full_body .= "\n\n--- ATTACHMENTS ---\n" . $attachment_text;
            }

            $consolidated_data[] = [
                'subject' => $message->get_header('subject') ?: '(No Subject)',
                'from' => $sender_name,
                'date' => $date_display,
                // Preview for list
                'body' => substr($plain_body, 0, 200),
                // Longer body for AI context including attachments
                'full_body' => $full_body,
                'has_attachments' => $has_attachments ? 'Yes' : 'No'
            ];
        }

        $rcmail->output->set_env('ai_results', $consolidated_data);
        $rcmail->output->send('ai_config.main');
    }

    function action_llm_window()
    {
        $rcmail = rcmail::get_instance();

        $selected_json = rcube_utils::get_input_value('selected_emails', rcube_utils::INPUT_POST);
        $this->selected_emails = json_decode($selected_json, true) ?: [];

        $this->register_handler('plugin.render_selected_emails', [$this, 'render_selected_emails']);
        $this->register_handler('plugin.raw_context_data', [$this, 'render_raw_context']);

        $rcmail->output->set_pagetitle('Gemini AI Assistant');
        $rcmail->output->send('ai_config.llm_window');
    }

    function render_selected_emails()
    {
        $html = '';
        foreach ($this->selected_emails as $email) {
            $html .= '<div class="email-ref">';
            $html .= '<strong>From:</strong> ' . htmlspecialchars($email['from']) . '<br>';
            $html .= '<strong>Subject:</strong> ' . htmlspecialchars($email['subject']) . '<br>';
            $html .= '<strong>Date:</strong> ' . htmlspecialchars($email['date']);
            $html .= '</div>';
        }
        if (empty($html)) {
            $html = '<em>No emails selected.</em>';
        }
        return $html;
    }

    function render_raw_context()
    {
        $text = "";
        foreach ($this->selected_emails as $i => $email) {
            $text .= "Email #" . ($i + 1) . ":\n";
            $text .= "From: " . $email['from'] . "\n";
            $text .= "Subject: " . $email['subject'] . "\n";
            $text .= "Date: " . $email['date'] . "\n";
            // Use full_body if available, fallback to body
            $body_content = $email['full_body'] ?? $email['body'];
            $text .= "Body: " . $body_content . "\n";
            $text .= "-----------------------------------\n";
        }
        return htmlspecialchars($text);
    }
}
