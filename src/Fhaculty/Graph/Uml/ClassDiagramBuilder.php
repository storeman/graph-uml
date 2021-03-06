<?php

namespace Fhaculty\Graph\Uml;

use Exception;
use Fhaculty\Graph\Algorithm\ConnectedComponents as AlgorithmConnectedComponents;
use Fhaculty\Graph\Graph;
use Fhaculty\Graph\GraphViz;
use Fhaculty\Graph\Vertex;
use ReflectionClass;
use ReflectionExtension;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * UML class diagram builder
 *
 * @author clue
 * @link   http://www.johndeacon.net/UML/UML_Appendix/Generated/UML_Appendix.asp
 * @link   http://www.ffnn.nl/pages/articles/media/uml-diagrams-using-graphviz-dot.php
 * @link   http://www.holub.com/goodies/uml/
 */
class ClassDiagramBuilder
{
    /**
     * Graph instance to operate on
     *
     * @var Graph
     */
    private $graph;

    private $options = [
        // whether to only show methods/properties that are actually defined in this class (and not those merely inherited from base)
        'only-self' => true,
        // whether to also show private methods/properties
        'show-private' => false,
        // whether to also show protected methods/properties
        'show-protected' => true,
        // whether to show class constants as readonly static variables (or just omit them completely)
        'show-constants' => true,
        // whether to show add parent classes or interfaces
        'add-parents' => true,
    ];

    public function __construct(Graph $graph)
    {
        $this->graph = $graph;
    }

    public function setOption($name, $flag)
    {
        if (!isset($this->options[$name])) {
            throw new Exception('Invalid option name "' . $name . '"');
        }
        $this->options[$name] = !!$flag;

        return $this;
    }

    public function hasClass($class)
    {
        try {
            $this->graph->getVertex($class);

            return true;
        } catch (Exception $ignroe) {
        }

        return false;
    }

    public function createVertexClass($class): \Fhaculty\Graph\Vertex
    {
        if ($class instanceof ReflectionClass) {
            $reflection = $class;
            $class = $reflection->getName();
        } else {
            // Reflection works without first \ so make sure we don't inject them
            $class = ltrim($class, '\\');
            $reflection = new ReflectionClass($class);
        }
        $vertex = $this->graph->createVertex($class);
        if ($this->options['add-parents']) {
            $parent = $reflection->getParentClass();
            if ($parent) {
                try {
                    $parentVertex = $this->graph->getVertex($parent->getName());
                } catch (Exception $ignore) {
                    $parentVertex = $this->createVertexClass($parent);
                }
                $vertex->createEdgeTo($parentVertex)->setLayoutAttribute('arrowhead', 'empty');
            }

            foreach ($this->getInterfaces($reflection) as $interface) {
                try {
                    $parentVertex = $this->graph->getVertex($interface->getName());
                } catch (Exception $ignore) {
                    $parentVertex = $this->createVertexClass($interface);
                }
                $vertex->createEdgeTo($parentVertex)->setLayoutAttribute('arrowhead', 'empty')->setLayoutAttribute('style', 'dashed');
            }
        }

        $vertex->setLayoutAttribute('shape', 'record');
        $vertex->setLayoutAttribute('label', GraphViz::raw($this->getLabelRecordClass($reflection)));

        return $vertex;
    }

    public function createVertexExtension($extension)
    {
        if ($extension instanceof ReflectionExtension) {
            $reflection = $class;
            $extension = $reflection->getName();
        } else {
            $reflection = new ReflectionExtension($extension);
        }

        $vertex = $this->graph->createVertex($extension);
        $vertex->setLayoutAttribute('shape', 'record');
        $vertex->setLayoutAttribute('label', GraphViz::raw($this->getLabelRecordExtension($reflection)));

        return $vertex;
    }

    /**
     * get label (for shape record) for the given reflection class
     *
     * @param ReflectionClass $reflection
     *
     * @return string
     *
     * @see http://graphviz.org/content/node-shapes#record
     * @see http://graphviz.org/content/attrs#kescString
     */
    protected function getLabelRecordClass(ReflectionClass $reflection)
    {
        $class = $reflection->getName();
        $parent = $reflection->getParentClass();

        // start 'over'
        $label = '"{';

        $isInterface = false;
        if ($reflection->isInterface()) {
            $label .= '«interface»\\n';
            $isInterface = true;
        } elseif ($reflection->isAbstract()) {
            $label .= '«abstract»\\n';
        }

        // new cell
        $label .= $this->escape($class) . '|';

        $label .= $this->getLabelRecordConstants($reflection);

        $defaults = $reflection->getDefaultProperties();
        foreach ($reflection->getProperties() as $property) {
            if ($this->options['only-self'] && $property->getDeclaringClass()->getName() !== $class) continue;

            if (!$this->isVisible($property)) continue;

            $label .= $this->visibility($property);
            if ($property->isStatic()) {
                $label .= ' «static»';
            }
            $label .= ' ' . $this->escape($property->getName());

            $type = $this->getDocBlockVar($property);
            if ($type !== null) {
                $label .= ' : ' . $this->escape($type);
            }

            // only show non-NULL values
            if (isset($defaults[$property->getName()])) {
                $label .= ' = ' . $this->getCasted($defaults[$property->getName()]);
            }

            // align this line to the left
            $label .= '\\l';
        }

        // new cell
        $label .= '|';

        $label .= $this->getLabelRecordFunctions($reflection->getMethods(), $class);

        // end 'over'
        $label .= '}"';

        return $label;
    }

    /**
     * get label (for shape record) for the given reflection extension module
     *
     * @param ReflectionExtension $reflection
     *
     * @return string
     */
    protected function getLabelRecordExtension(ReflectionExtension $reflection)
    {
        $extension = $reflection->getName();

        $label = '"{';

        $label .= '«extension»\\n';
        $label .= $this->escape($extension);

        $label .= '|';

        $label .= $this->getLabelRecordConstants($reflection);

        $label .= '|';

        $label .= $this->getLabelRecordFunctions($reflection->getFunctions());

        $label .= '}"';

        return $label;
    }

    /**
     * get string describing the constants from the given reflection class or extension module
     *
     * @param ReflectionClass|ReflectionExtension $reflection
     *
     * @return string
     */
    protected function getLabelRecordConstants($reflection)
    {
        $label = '';
        if ($this->options['show-constants']) {
            $parent = null;
            if ($reflection instanceof ReflectionClass) {
                $parent = $reflection->getParentClass();
            }
            foreach ($reflection->getConstants() as $name => $value) {
                if ($this->options['only-self'] && $parent && $parent->getConstant($name) === $value) continue;

                $label .= '+ «static» ' . $this->escape($name) . ' : ' . $this->escape($this->getType(gettype($value))) . ' = ' . $this->getCasted($value) . ' \\{readOnly\\}\\l';
            }
        }

        return $label;
    }

    /**
     * get string describing the given array of reflection methods / functions
     *
     * @param ReflectionMethod[]|ReflectionFunction[] $functions
     * @param string|null                             $class
     *
     * @return string
     */
    protected function getLabelRecordFunctions(array $functions, $class = null)
    {
        $label = '';
        foreach ($functions as $method) {
            if ($method instanceof ReflectionMethod) {
                // method not defined in this class (inherited from parent), so skip
                if ($this->options['only-self'] && $method->getDeclaringClass()->getName() !== $class) continue;

                if (!$this->isVisible($method)) continue;

                // $ref = preg_replace('/[^a-z0-9]/i', '', $method->getName());
                // $label .= '<"' . $ref . '">';

                $label .= $this->visibility($method);

                if (/*!$isInterface && */
                $method->isAbstract()) {
                    $label .= ' «abstract»';
                }
                if ($method->isStatic()) {
                    $label .= ' «static»';
                }
            } else {
                // ReflectionFunction does not define any of the above accessors
                // simply pretend this is a "normal" public method
                $label .= '+ ';
            }
            $label .= ' ' . $this->escape($method->getName()) . '(';

            $firstParam = true;
            foreach ($method->getParameters() as $parameter) {
                /* @var $parameter ReflectionParameter */
                if ($firstParam) {
                    $firstParam = false;
                } else {
                    $label .= ', ';
                }

                if ($parameter->isPassedByReference()) {
                    $label .= 'inout ';
                }

                $label .= $this->escape($parameter->getName());

                $type = $this->getParameterType($parameter);
                if ($type !== null) {
                    $label .= ' : ' . $this->escape($type);
                }

                if ($parameter->isOptional()) {
                    try {
                        $label .= ' = ' . $this->getCasted($parameter->getDefaultValue());
                    } catch (Exception $ignore) {
                        $label .= ' = «unknown»';
                    }
                }
            }
            $label .= ')';

            $type = $this->getDocBlockReturn($method);
            if ($type !== null) {
                $label .= ' : ' . $this->escape($type);
            }

            // align this line to the left
            $label .= '\\l';
        }

        return $label;
    }

    /**
     * check if the given method/property reflection object should be visible
     *
     * @param ReflectionClass|ReflectionProperty $reflection
     *
     * @return boolean
     */
    protected function isVisible($reflection)
    {
        return ($reflection->isPublic() ||
            ($reflection->isProtected() && $this->options['show-protected']) ||
            ($reflection->isPrivate() && $this->options['show-private']));
    }

    /**
     * create new uml note (attached to given class vertex)
     *
     * @param  string      $note
     * @param  Vertex|NULL $for
     *
     * @return LoaderUmlClassDiagram $this (chainable)
     */
    public function createVertexNote($note, $for = null)
    {
        $vertex = $this->graph->createVertex()->setLayoutAttribute('label', $note . "\n")
            ->setLayoutAttribute('shape', 'note')
            ->setLayoutAttribute('fontsize', 8)
            // ->setLayoutAttribute('margin', '0 0')
            ->setLayoutAttribute('style', 'filled')
            ->setLayoutAttribute('fillcolor', 'yellow');

        if ($for !== null) {
            $vertex->createEdgeTo($for)->setLayoutAttribute('len', 1)
                ->setLayoutAttribute('style', 'dashed')
                ->setLayoutAttribute('arrowhead', 'none');
        }

        return $vertex;
    }

    /**
     * create subgraph for all classes connected to given class (i.e. return it's connected component)
     *
     * @param  string $class
     *
     * @return Graph
     * @throws Exception
     */
    public function createGraphComponent($class)
    {
        try {
            $vertex = $this->graph->getVertex($class);
        } catch (Exception $e) {
            throw new Exception('Given class is unknown');
        }
        $alg = new AlgorithmConnectedComponents($this->graph);

        return $alg->createGraphComponentVertex($vertex);
    }

    /**
     * create a separate graph for each connected component
     *
     * @return Graph[]
     * @uses AlgorithmConnectedComponents::createGraphsComponents()
     */
    public function createGraphsComponents()
    {
        $alg = new AlgorithmConnectedComponents($this->graph);

        return $alg->createGraphsComponents();
    }

    /**
     * get total number of connected components
     *
     * @return int
     * @uses AlgorithmConnectedComponents::getNumberOfComponents()
     */
    public function getNumberOfComponents()
    {
        $alg = new AlgorithmConnectedComponents($this->graph);

        return $alg->getNumberOfComponents();
    }

    private function getDocBlock($ref)
    {
        $doc = $ref->getDocComment();
        if ($doc !== false) {
            return trim(preg_replace('/(^(?:\h*\*)\h*|\h+$)/m', '', substr($doc, 3, -2)));
        }

        return null;
    }

    private function getDocBlockVar($ref)
    {
        return $this->getType($this->getDocBlockSingle($ref, 'var'));
    }

    private function getDocBlockReturn($ref)
    {
        return $this->getType($this->getDocBlockSingle($ref, 'return'));
    }

    private function getParameterType(ReflectionParameter $parameter)
    {
        $class = null;
        try {
            // get class hint for parameter
            $class = $parameter->getClass();
            // will fail if specified class does not exist
        } catch (Exception $ignore) {
            return '«invalidClass»';
        }

        if ($class !== null) {
            return $class->getName();
        }

        $pos = $parameter->getPosition();
        $refFn = $parameter->getDeclaringFunction();
        $params = $this->getDocBlockMulti($refFn, 'param');
        if (count($params) === $refFn->getNumberOfParameters()) {
            return $this->getType($params[$pos]);
        }

        return null;
    }

    private function getDocBlockMulti($ref, $what): array
    {
        $doc = $this->getDocBlock($ref);
        if ($doc === null) {
            return [];
        }
        preg_match_all('/^@' . $what . ' ([^\s]+)/m', $doc, $matches, PREG_SET_ORDER);
        $ret = [];
        foreach ($matches as $match) {
            $ret [] = trim($match[1]);
        }

        return $ret;
    }

    private function getDocBlockSingle($ref, $what)
    {
        $multi = $this->getDocBlockMulti($ref, $what);
        if (count($multi) !== 1) {
            // return json_encode($matches);
            return null;
        }

        return $multi[0];
    }

    private function getType(string $ret = null): ?string
    {
        if ($ret === null) {
            return null;
        }
        if (preg_match('/^array\[(\w+)\]$/i', $ret, $match)) {
            return $this->getType($match[1]) . '[]';
        }
        if (!preg_match('/^\w+$/', $ret)) {
            return 'mixed';
        }
        $low = strtolower($ret);
        if ($low === 'integer') {
            $ret = 'int';
        } elseif ($low === 'double') {
            $ret = 'float';
        } elseif ($low === 'boolean') {
            return 'bool';
        } elseif (in_array($low, ['int', 'float', 'bool', 'string', 'null', 'resource', 'array', 'void', 'mixed'])) {
            return $low;
        }

        return $ret;
    }

    /**
     * get given value casted to string (and escaped in double quotes it needed)
     *
     * @param  mixed $value
     *
     * @return string
     * @uses LoaderUmlClassDiagram::escape()
     */
    private function getCasted($value)
    {
        if ($value === null) {
            return 'NULL';
        } elseif (is_string($value)) {
            return '\\"' . $this->escape(str_replace('"', '\\"', $value)) . '\\"';
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (is_int($value) || is_float($value)) {
            return (string)$value;
        } elseif (is_array($value)) {
            if ($value === []) {
                return '[]';
            } else {
                return '[…]';
            }
        } elseif (is_object($value)) {
            return get_class($value) . '\\{…\\}';
        }

        return '…';
    }

    private function visibility($ref)
    {
        if ($ref->isPublic()) {
            return '+';
        } elseif ($ref->isProtected()) {
            return '#';
        } elseif ($ref->isPrivate()) {
            // U+2013 EN DASH "–"
            return "\342\200\223";
        }

        return '?';
    }

    private function escape($id)
    {
        return preg_replace('/([^\\w])/u', '\\\\$1', str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $id));
    }

    private function getInterfaces(ReflectionClass $reflection)
    {
        // a list of all interfaces implemented explicitly or implicitly
        $interfaces = $reflection->getInterfaces();

        // remove each interface already implemented by the parent class (if any)
        $parent = $reflection->getParentClass();
        if ($parent) {
            foreach ($parent->getInterfaceNames() as $in) {
                unset($interfaces[$in]);
            }
        }

        // remove each interface already implemented by any of the inherited interfaces
        foreach ($interfaces as $if) {
            foreach ($if->getInterfaceNames() as $in) {
                unset($interfaces[$in]);
            }
        }

        return $interfaces;
    }
}
