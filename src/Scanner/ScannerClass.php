<?php

namespace Zend\Code\Scanner;

class ScannerClass implements ScannerInterface
{
    protected $isScanned = false;
    
    protected $namespace = null;
    protected $uses = array();
    protected $name = null;
    protected $shortName = null;
    protected $isFinal = false;
    protected $isAbstract = false;
    protected $isInterface = false;
    
    protected $parentClass = null;
    protected $shortParentClass = null;
    
    protected $interfaces = array();
    protected $shortInterfaces = array();
    
    protected $tokens = array();
    protected $infos = array();
    
    public function __construct(array $classTokens, $namespace = null, array $uses = array())
    {
        $this->tokens = $classTokens;
        $this->namespace = $namespace;
        $this->uses = $uses;
    }
    
    protected function scan()
    {
        if (!$this->tokens) {
            throw new \RuntimeException('No tokens were provided');
        }
        
        for ($tokenIndex = 0; $tokenIndex < count($this->tokens); $tokenIndex++) {
            $token = $this->tokens[$tokenIndex];

            if (is_string($token)) {
                continue;
            }
            
            // tokens with some value are arrays (will have a token identifier, & line num)
            $fastForward = 0;
            switch ($token[0]) {
                case T_CLASS:
                case T_INTERFACE:
                    $this->scanClassInfo($tokenIndex, $fastForward);
                    break;
                
                case T_CONST:
                    $this->scanConstant($tokenIndex, $fastForward);
                    break;
                case T_FINAL:
                case T_ABSTRACT:
                    if (!$this->name) {
                        break;
                    }
                case T_PUBLIC:
                case T_PROTECTED:
                case T_PRIVATE:
                case T_STATIC:
                case T_FUNCTION:
                case T_VAR:
                    $subTokenIndex = $tokenIndex;
                    do {
                        $subToken = $this->tokens[$subTokenIndex++];
                    } while (!(is_array($subToken) && $subToken[0] == T_FUNCTION) && !(is_string($subToken) && $subToken == '='));

                    if (is_array($subToken)) {
                        $this->scanMethod($tokenIndex, $fastForward);
                    } else {
                        $this->scanProperty($tokenIndex, $fastForward);
                    }
                    
                    break;
            }

            if ($fastForward) {
                $tokenIndex += $fastForward - 1;
            }
        }
        
        $this->isScanned = true;
    }
    
    protected function scanClassInfo($tokenIndex, &$fastForward)
    {
        if (isset($this->tokens[$tokenIndex-2]) && is_array($this->tokens[$tokenIndex-2])) {
            $tokenTwoBack = $this->tokens[$tokenIndex-2];
        }
        
        // T_ABSTRACT & T_FINAL will have been bypassed if no class name, and will alwasy be 2 tokens behind T_CLASS
        $this->isAbstract = (isset($tokenTwoBack) && ($tokenTwoBack[0] === T_ABSTRACT));
        $this->isFinal = (isset($tokenTwoBack) && ($tokenTwoBack[0] === T_FINAL));
        
        $this->isInterface = (is_array($this->tokens[$tokenIndex]) && $this->tokens[$tokenIndex][0] == T_INTERFACE);
        $this->shortName = $this->tokens[$tokenIndex+2][1];
        $this->name = (($this->namespace) ? $this->namespace . '\\' : '') . $this->shortName;
        
        
        $context = null;
        $interfaceIndex = 0;
        while (true) {
            $fastForward++;
            $tokenIndex++;
            $token = $this->tokens[$tokenIndex];
            
            // BREAK ON
            if (is_string($token) && $token == '{') {
                break;
            }
            
            // ANALYZE
            if (is_string($token) && $context == T_IMPLEMENTS && $token == ',') {
                $interfaceIndex++;
                $this->shortInterfaces[$interfaceIndex] = '';
            }
            
            if (is_array($token)) {
                if ($token[0] == T_NS_SEPARATOR || $token[0] == T_STRING) {
                    if ($context == T_EXTENDS) {
                        $this->shortParentClass .= $token[1];
                    } elseif ($context == T_IMPLEMENTS) {
                        $this->shortInterfaces[$interfaceIndex] .= $token[1];
                    }
                }
                if ($token[0] == T_EXTENDS) {
                    die('found extends');
                    $fastForward += 2;
                    $tokenIndex += 2;
                    $this->shortParentClass = '';
                }
                if ($token[0] == T_IMPLEMENTS) {
                    $context = T_IMPLEMENTS;
                    $this->shortInterfaces[$interfaceIndex] = '';
                }
            }

        }
        
        // create function to resolve short names with uses
        $namespace = $this->namespace;
        $uses = $this->uses;
        $resolveUseFunc = function (&$value, $key = null) use (&$namespace, &$uses) {
            if (!$uses || strlen($value) <= 0 || $value{0} == '\\') {
                $value = ltrim($value, '\\');
                return;
            }
            
            if ($namespace || $uses) {
                $firstPartEnd = (strpos($value, '\\')) ?: strlen($value-1);
                $firstPart = substr($value, 0, $firstPartEnd);
                if (array_key_exists($firstPart, $uses)) {
                    $value = substr_replace($value, $uses[$firstPart], 0, $firstPartEnd);
                    return;
                }
                if ($namespace) {
                    $value = $namespace . '\\' . $value;
                    return;
                }
            }
        };
        
        if ($this->shortInterfaces) {
            $this->interfaces = $this->shortInterfaces;
            array_walk($this->interfaces, $resolveUseFunc);
        }
        
        if ($this->shortParentClass) {
            $this->parentClass = $this->shortParentClass;
            $resolveUseFunc($this->parentClass);
        }

    }
    
    protected function scanConstant($tokenIndex, &$fastForward)
    {
        $info = array(
            'type'       => 'constant',
            'tokenStart' => $tokenIndex,
            'tokenEnd'   => null,
            'lineStart'  => $this->tokens[$tokenIndex][2],
            'lineEnd'    => null,
            'name'       => null,
            'value'	     => null
            );
            
        while (true) {
            $fastForward++;
            $tokenIndex++;
            $token = $this->tokens[$tokenIndex];
            
            // BREAK ON
            if (is_string($token) && $token == ';') {
                break;
            }
            
            if ((is_array($token) && $token[0] == T_WHITESPACE) || (is_string($token) && $token == '=')) {
                continue;
            }

            $info['value'] .= (is_array($token)) ? $token[1] : $token;
            
            if (is_array($token)) {
                $info['lineEnd'] = $token[2];
            }
            
        }
        
        $info['tokenEnd'] = $tokenIndex;
        $this->infos[] = $info;
    }
    
    protected function scanMethod($tokenIndex, &$fastForward)
    {
        $info = array(
        	'type'        => 'method',
            'tokenStart'  => $tokenIndex,
            'tokenEnd'    => null,
            'lineStart'   => $this->tokens[$tokenIndex][2],
            'lineEnd'     => null,
            'name'        => null
            );
        
        $braceCount = 0;
        while (true) {
            $fastForward++;
            $tokenIndex++;
            $token = $this->tokens[$tokenIndex];
            
            // BREAK ON
            if (is_string($token) && $token == '}' && $braceCount == 1) {
                break;
            }
            
            // ANALYZE
            if (is_string($token)) {
                if ($token == '{') {
                    $braceCount++;
                }
                if ($token == '}') {
                    $braceCount--;
                }
            }
            
            if ($token[0] === T_FUNCTION) {
                // next token after T_WHITESPACE is name
                $info['name'] = $this->tokens[$tokenIndex+2][1];
                continue;
            } 
            
            if (is_array($token)) {
                $info['lineEnd'] = $token[2];
            }
            
        }
        
        $info['tokenEnd'] = $tokenIndex;
        $this->infos[] = $info;
    }
    
    protected function scanProperty($tokenIndex, &$fastForward)
    {
        $info = array(
        	'type'        => 'property',
            'tokenStart'  => $tokenIndex,
            'tokenEnd'    => null,
            'lineStart'   => $this->tokens[$tokenIndex][2],
            'lineEnd'     => null,
            'name'        => null
            );
        
        $index = $tokenIndex;

        while (true) {
            $fastForward++;
            $tokenIndex++;
            $token = $this->tokens[$tokenIndex];
            
            // BREAK ON
            if (is_string($token) && $token = ';') {
                break;
            }
            
            // ANALYZE
            if ($token[0] === T_VARIABLE) {
                $info['name'] = ltrim($token[1], '$');
                continue;
            }
            
            if (is_array($token)) {
                $info['lineEnd'] = $token[2];
            }
            
        }
        
        $info['tokenEnd'] = $index;
        $this->infos[] = $info;
    }
    
    public function getName()
    {
        $this->scan();
        return $this->name;
    }
    
    public function getShortName()
    {
        $this->scan();
        return $this->shortName;
    }
    
    public function isFinal()
    {
        $this->scan();
        return $this->isFinal;
    }

    public function isAbstract()
    {
        $this->scan();
        return $this->isAbstract;
    }
    
    public function isInterface()
    {
        $this->scan();
        return $this->isInterface;
    }

    public function getInterfaces()
    {
        $this->scan();
        return $this->interfaces;
    }
    
    public function getConstants()
    {
        $this->scan();
        
        $return = array();
        
        foreach ($this->infos as $info) {
            if ($info['type'] != 'constant') {
                continue;
            }

            //if (!$returnScanner) {
                $return[] = $info['name'];
            //} else {
            //    $return[] = $this->getClass($info['name'], $returnScannerProperty);
            //}
        }
        return $return;
    }
    
    public function getProperties($returnScannerProperty = false)
    {
        $this->scan();
        
        $return = array();
        
        foreach ($this->infos as $info) {
            if ($info['type'] != 'property') {
                continue;
            }

            if (!$returnScannerProperty) {
                $return[] = $info['name'];
            } else {
                $return[] = $this->getClass($info['name'], $returnScannerProperty);
            }
        }
        return $return;
    }
    
    public function getMethods($returnScannerMethod = false)
    {
        $this->scan();
        
        $return = array();
        
        foreach ($this->infos as $info) {
            if ($info['type'] != 'method') {
                continue;
            }

            if (!$returnScannerMethod) {
                $return[] = $info['name'];
            } else {
                $return[] = $this->getMethod($info['name'], $returnScannerMethod);
            }
        }
        return $return;
    }
    
    public function getMethod($methodNameOrInfoIndex, $returnScannerClass = 'Zend\Code\Scanner\ScannerMethod')
    {
        $this->scan();
        
        // process the class requested
        static $baseScannerClass = 'Zend\Code\Scanner\ScannerMethod';
        if ($returnScannerClass !== $baseScannerClass) {
            if (!is_string($returnScannerClass)) {
                $returnScannerClass = $baseScannerClass;
            }
            $returnScannerClass = ltrim($returnScannerClass, '\\');
            if ($returnScannerClass !== $baseScannerClass && !is_subclass_of($returnScannerClass, $baseScannerClass)) {
                throw new \RuntimeException('Class must be or extend ' . $baseScannerClass);
            }
        }
        
        if (is_int($methodNameOrInfoIndex)) {
            $info = $this->infos[$methodNameOrInfoIndex];
            if ($info['type'] != 'method') {
                throw new \InvalidArgumentException('Index of info offset is not about a method');
            }
        } elseif (is_string($methodNameOrInfoIndex)) {
            $methodFound = false;
            foreach ($this->infos as $infoIndex => $info) {
                if ($info['type'] === 'method' && $info['name'] === $methodNameOrInfoIndex) {
                    $methodFound = true;
                    break;
                }
            }
            if (!$methodFound) {
                return false;
            }
        }
        
        return new $returnScannerClass(
            array_slice($this->tokens, $info['tokenStart'], $info['tokenEnd'] - $info['tokenStart'] - 1),
            $this->name,
            $this->uses
            );
    }
    
    public static function export()
    {
        // @todo
    }
    
    public function __toString()
    {
        // @todo
    }
    
}
