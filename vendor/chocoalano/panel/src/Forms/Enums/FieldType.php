<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Enums;

/**
 * The control a field renders as, and the discriminant of the field
 * definition union on the frontend.
 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Password = 'password';
    case Number = 'number';
    case Money = 'money';
    case Hidden = 'hidden';
    case Checkbox = 'checkbox';
    case Toggle = 'toggle';
    case Select = 'select';
    case Date = 'date';
    case DateTime = 'datetime';
    case Time = 'time';
    case Radio = 'radio';
    case CheckboxList = 'checkbox_list';
    case ToggleButtons = 'toggle_buttons';
    case ColorPicker = 'color_picker';
    case Slider = 'slider';
    case TagsInput = 'tags_input';
    case KeyValue = 'key_value';
    case RichEditor = 'rich_editor';
    case MarkdownEditor = 'markdown_editor';
    case CodeEditor = 'code_editor';
    case FileUpload = 'file_upload';
    case Repeater = 'repeater';
    case Builder = 'builder';
    case Custom = 'custom';
}
