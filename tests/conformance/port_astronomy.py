"""Reproduce the PHP numerical kernels from our sibling weewx-evo sources.

Only the named pure functions and coefficient tables are translated. No source
is executed. The conformance suite independently compares the result to PyEphem.
Usage: python tests/conformance/port_astronomy.py ../weewx-evo/src/weewx_evo
"""
import ast
import pathlib
import sys

source = pathlib.Path(sys.argv[1])
destination = pathlib.Path(__file__).resolve().parents[2] / 'src' / 'Astronomy'

def generate(module, classname, selected, tables):
    tree = ast.parse((source / (module + '.py')).read_text(encoding='utf-8'))
    def expr(n):
        if isinstance(n, ast.Constant):
            return {None: 'null', True: 'true', False: 'false'}.get(n.value, repr(n.value)) if n.value is None or isinstance(n.value, bool) else repr(n.value)
        if isinstance(n, ast.Name):
            return 'self::' + n.id if n.id in tables else '$' + n.id
        if isinstance(n, (ast.Tuple, ast.List)):
            return '[' + ', '.join(map(expr, n.elts)) + ']'
        if isinstance(n, ast.BinOp):
            a, b = expr(n.left), expr(n.right)
            if isinstance(n.op, ast.Mod):
                return f'self::mod({a}, {b})'
            op = {ast.Add: '+', ast.Sub: '-', ast.Mult: '*', ast.Div: '/', ast.Pow: '**'}[type(n.op)]
            return f'({a} {op} {b})'
        if isinstance(n, ast.UnaryOp):
            return ('-' if isinstance(n.op, ast.USub) else '!') + '(' + expr(n.operand) + ')'
        if isinstance(n, ast.Compare):
            if isinstance(n.ops[0], ast.Eq):
                return f'({expr(n.left)} === {float(n.comparators[0].value)!r})'
            op = {ast.Eq: '==', ast.Lt: '<', ast.Gt: '>', ast.LtE: '<=', ast.GtE: '>='}[type(n.ops[0])]
            return f'({expr(n.left)} {op} {expr(n.comparators[0])})'
        if isinstance(n, ast.BoolOp):
            return '(' + (' && ' if isinstance(n.op, ast.And) else ' || ').join(map(expr, n.values)) + ')'
        if isinstance(n, ast.IfExp):
            return f'({expr(n.test)} ? {expr(n.body)} : {expr(n.orelse)})'
        if isinstance(n, ast.Subscript):
            return expr(n.value) + '[' + expr(n.slice) + ']'
        if isinstance(n, ast.Call):
            name = n.func.attr if isinstance(n.func, ast.Attribute) else n.func.id
            name = {'radians': 'deg2rad', 'degrees': 'rad2deg', 'delta_t': 'Seasons::delta_t'}.get(name, name)
            if name in selected:
                name = 'self::' + name
            return name + '(' + ', '.join(map(expr, n.args)) + ')'
        raise ValueError(ast.dump(n))
    def statements(nodes, indent=8):
        out = []
        p = ' ' * indent
        for n in nodes:
            if isinstance(n, (ast.Expr, ast.ImportFrom)):
                continue
            if isinstance(n, ast.Assign):
                if isinstance(n.value, ast.Call) and isinstance(n.value.func, ast.Name) and n.value.func.id == 'sum':
                    gen = n.value.args[0]
                    loop = gen.generators[0]
                    target = expr(n.targets[0])
                    out += [p + target + ' = 0.0;', p + f'foreach ({expr(loop.iter)} as {expr(loop.target)}) {{', p + '    ' + target + ' += ' + expr(gen.elt) + ';', p + '}']
                else:
                    out.append(p + expr(n.targets[0]) + ' = ' + expr(n.value) + ';')
            elif isinstance(n, ast.AugAssign):
                out.append(p + expr(n.target) + (' += ' if isinstance(n.op, ast.Add) else ' *= ') + expr(n.value) + ';')
            elif isinstance(n, ast.Return):
                if isinstance(n.value, ast.Call) and isinstance(n.value.func, ast.Name) and n.value.func.id == 'sum':
                    gen = n.value.args[0]
                    loop = gen.generators[0]
                    out += [p + '$total = 0.0;', p + f'foreach ({expr(loop.iter)} as {expr(loop.target)}) {{', p + '    $total += ' + expr(gen.elt) + ';', p + '}', p + 'return $total;']
                else:
                    out.append(p + 'return ' + expr(n.value) + ';')
            elif isinstance(n, ast.If):
                out += [p + 'if (' + expr(n.test) + ') {', *statements(n.body, indent + 4), p + '}']
                if n.orelse:
                    out += [p + 'else {', *statements(n.orelse, indent + 4), p + '}']
            elif isinstance(n, ast.For):
                if isinstance(n.iter, ast.Call) and n.iter.func.id == 'range':
                    out.append(p + 'for ($i = 0; $i < ' + expr(n.iter.args[0]) + '; ++$i) {')
                else:
                    out.append(p + 'foreach (' + expr(n.iter) + ' as ' + expr(n.target) + ') {')
                out += [*statements(n.body, indent + 4), p + '}']
            else:
                raise ValueError(ast.dump(n))
        return out
    out = ['<?php', '', 'declare(strict_types=1);', '', 'namespace WeewxPhp\\Astronomy;', '', '/** Numerical kernel ported from our weewx-evo/' + module + '.py. See SOURCES.md. */', 'final class ' + classname, '{']
    for node in tree.body:
        if isinstance(node, ast.Assign) and node.targets[0].id in tables:
            out += ['    private const ' + node.targets[0].id + ' = ' + expr(node.value) + ';', '']
        if isinstance(node, ast.FunctionDef) and node.name in selected:
            signature, result = selected[node.name]
            if result == 'array':
                out += ['    /** @return array{float, float, float} */']
            out += ['    public static function ' + node.name + '(' + signature + '): ' + result, '    {', *statements(node.body), '    }', '']
    if any('self::mod(' in line for line in out):
        out += ['    private static function mod(float $a, float $b): float', '    {', '        return $a - $b * floor($a / $b);', '    }']
    out += ['}']
    (destination / (classname + '.php')).write_text('\n'.join(out) + '\n', encoding='utf-8')

generate('moon', 'Lunar', {
    'julian_centuries': ('float $when', 'float'),
    'position': ('float $when', 'array'),
    'equatorial': ('float $when', 'array'),
    'phase_event': ('float $when, float $phase, bool $forwards = true', 'float'),
    '_phase_moment': ('float $k', 'float'),
    '_additional': ('float $k, float $t', 'float'),
    '_delta_t': ('float $k', 'float'),
    '_obliquity': ('float $t', 'float'),
    '_gmst': ('float $when', 'float'),
}, {'LONGITUDE_TERMS', 'LATITUDE_TERMS', 'EARTH_RADIUS'})
generate('sun', 'Seasons', {
    '_season_moment': ('float $year, int $which', 'float'),
    'delta_t': ('float $year', 'float'),
}, {'SEASON_MEANS', 'SEASON_TERMS'})
