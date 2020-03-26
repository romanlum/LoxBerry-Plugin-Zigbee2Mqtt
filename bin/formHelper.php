<?php
#use from https://dev.to/emanuelvintila/creating-an-html-form-for-a-class-part-2-5cih

function ParseDocCommentAnnotations(string $comment): array
{
    $matches = null;
    $annotations = [];
    $lines = explode("\n", $comment);
    foreach ($lines as $line) {
        $line = trim($line);
        // match all lines that start with an @ and capture the relevant parts
        if (1 === preg_match('/^\/?\*+\s*(@.*?)(?:\s*\*\/)?$/', $line,
                $matches)) {
            $annotation = $matches[1];
            // eventually split them on white-space, to handle @var annotations
            // or others that might also specify a value, such as @min, @max etc.
            list($key, $value) = preg_split('/\s+/', $annotation, 2);
            // if the value is falsy, use the key as the value
            // we could use literal true instead or whatever makes sense
            $value = $value ?? $key;
            $annotations[strtolower($key)] = $value;
        }
    }

    return $annotations;
}

/**
 * This function creates a valid HTML input with an associated label.
 * @param ReflectionProperty $property
 * @param mixed $object An instance of the class that the $property parameter
 * was declared into
 * @return string
 */
function MakeInput(ReflectionProperty $property, $object): string
{
    $annotations = ParseDocCommentAnnotations($property->getDocComment());
    if (false === array_key_exists('@var', $annotations))
        // we could throw an exception instead
        // or assume that the property is a string
        return '';

    $class = $property->getDeclaringClass();
    $name = $property->getName();
    $value = $property->getValue($object);
    $input_name = "{$class->getName()}[{$name}]";
    $is_textarea = array_key_exists('@textarea', $annotations) &&
        $annotations['@var'] === 'string';
    // the for attribute targets an element with the specified id, not the name
    $label = sprintf('<label for="%s">%s</label>', $input_name, $name);

    $input_attributes = ['name' => $input_name, 'id' => $input_name];
    if (false === $is_textarea)
        $input_attributes['value'] = $value;
    if (array_key_exists('@readonly', $annotations))
        $input_attributes['readonly'] = 'readonly';

    switch ($type = $annotations['@var']) {
        case 'bool':
            $input_attributes['type'] = 'checkbox';
            break;
        case 'int':
        case 'double':
        case 'float':
            $input_attributes['type'] = 'number';
            // this is where the power of the annotations comes in
            // you can annotate your class's properties in any way you want
            // and then use those annotations to build the form
            if (array_key_exists('@min', $annotations))
                $input_attributes['min'] = $annotations['@min'];
            if (array_key_exists('@max', $annotations))
                $input_attributes['max'] = $annotations['@max'];
            if (array_key_exists('@step', $annotations))
                $input_attributes['step'] = $annotations['@step'];
            break;
        case 'string':
            if (false === $is_textarea)
                $input_attributes['type'] = 'text';
            break;
        default:
            // maybe the annotation specifies a class that we know how to
            // display as a form input
            $class = new ReflectionClass($type);
            if ($class->implementsInterface(DateTimeInterface::class)) {
                $input_attributes['type'] = 'date';
                break;
            }
            throw new InvalidArgumentException("The property {$name}
                    with type {$type} could not be converted into an input.");
    }

    $attributes_string = '';
    foreach ($input_attributes as $k => $v)
        $attributes_string .= sprintf(' %s="%s"', $k, addslashes($v));

    if (false === $is_textarea)
        $input = "<input {$attributes_string} />";
    else
        $input = "<textarea {$attributes_string}>{$value}</textarea>";

    return $label . $input;
}

function MakeInputs($object): array
{
    $inputs = [];
    $class = new ReflectionClass($object);
    $properties = $class->getProperties();
    foreach ($properties as $property)
        // we could use yield here and change the return type to Generator
        $inputs[] = MakeInput($property, $object);

    return $inputs;
}

/**
 * @param mixed $object An object instance of a class
 * that has its properties annotated
 * @return string
 */
function MakeForm($object): string
{
    $html = '<form method="POST">';
    foreach (MakeInputs($object) as $input)
        $html .= $input;
    $html .= '<input type="submit" />';
    $html .= '</form>';

    return $html;
}

function MakeObjectFromArray(ReflectionClass $class, array $values)
{
    // we do not call the constructor yet
    $instance = $class->newInstanceWithoutConstructor();
    // first we set each property to their respective value
    foreach ($values as $name => $value) {
        $property = $class->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }
    // note that we have set primitive values to our object properties
    // we late-call the constructor, like PDO does when fetching objects
    // and it re-creates the object instances from the primitive values
    $class->getConstructor()->invoke($instance);

    return $instance;
}