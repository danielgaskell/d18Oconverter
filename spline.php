<?php
//
// Original C# implementation (c) 2017 Scott W Harden
// Adaptation to PHP (c) 2022 Daniel E. Gaskell
//
// Permission is hereby granted, free of charge, to any person obtaining
// a copy of this software and associated documentation files (the
// "Software"), to deal in the Software without restriction, including
// without limitation the irrevocable, perpetual, worldwide, and royalty-free
// rights to use, copy, modify, merge, publish, distribute, sublicense, 
// display, perform, create derivative works from and/or sell copies of 
// the Software, both in source and object code form, and to
// permit persons to whom the Software is furnished to do so, subject to
// the following conditions:
// 
// The above copyright notice and this permission notice shall be
// included in all copies or substantial portions of the Software.
// 
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
// EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
// MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
// NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
// LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
// OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
// WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
//

function spline_interpolate($x_original, $y_original, $x_interpolated) {
	$n = count($x_original);
	$a = array_fill(0, $n - 1, 0);
    $b = array_fill(0, $n - 1, 0);
    $r = array_fill(0, $n, 0);
    $A = array_fill(0, $n, 0);
    $B = array_fill(0, $n, 0);
    $C = array_fill(0, $n, 0);

	$dx1 = $x_original[1] - $x_original[0];
	$C[0] = 1.0 / $dx1;
	$B[0] = 2.0 * $C[0];
	$r[0] = 3 * ($y_original[1] - $y_original[0]) / ($dx1 * $dx1);

	for ($i = 1; $i < $n - 1; $i++) {
		$dx1 = $x_original[$i] - $x_original[$i - 1];
		$dx2 = $x_original[$i + 1] - $x_original[$i];
		$A[$i] = 1.0 / $dx1;
		$C[$i] = 1.0 / $dx2;
		$B[$i] = 2.0 * ($A[$i] + $C[$i]);
		$dy1 = $y_original[$i] - $y_original[$i - 1];
		$dy2 = $y_original[$i + 1] - $y_original[$i];
		$r[$i] = 3 * ($dy1 / ($dx1 * $dx1) + $dy2 / ($dx2 * $dx2));
	}

	$dx1 = $x_original[$n - 1] - $x_original[$n - 2];
	$dy1 = $y_original[$n - 1] - $y_original[$n - 2];
	$A[$n - 1] = 1.0 / $dx1;
	$B[$n - 1] = 2.0 * $A[$n - 1];
	$r[$n - 1] = 3 * ($dy1 / ($dx1 * $dx1));

	$c_prime = array_fill(0, $n, 0);
	$c_prime[0] = $C[0] / $B[0];
	for ($i = 1; $i < $n; $i++)
		$c_prime[$i] = $C[$i] / ($B[$i] - $c_prime[$i - 1] * $A[$i]);

	$d_prime = array_fill(0, $n, 0);
	$d_prime[0] = $r[0] / $B[0];
	for ($i = 1; $i < $n; $i++)
		$d_prime[$i] = ($r[$i] - $d_prime[$i - 1] * $A[$i]) / ($B[$i] - $c_prime[$i - 1] * $A[$i]);

	$k = array_fill(0, $n, 0);
	$k[$n - 1] = $d_prime[$n - 1];
	for ($i = $n - 2; $i >= 0; $i--)
		$k[$i] = $d_prime[$i] - $c_prime[$i] * $k[$i + 1];

	for ($i = 1; $i < $n; $i++) {
		$dx1 = $x_original[$i] - $x_original[$i - 1];
		$dy1 = $y_original[$i] - $y_original[$i - 1];
		$a[$i - 1] =  $k[$i - 1] * $dx1 - $dy1;
		$b[$i - 1] = -$k[$i]     * $dx1 + $dy1;
	}
	
	$y_interpolated = array_fill(0, count($x_interpolated), 0);
	for ($i = 0; $i < count($x_interpolated); $i++) {
		for ($j = 0; $j < count($x_original) - 2; $j++) {
			if ($x_interpolated[$i] <= $x_original[$j + 1])
				break;
		}

		$dx = $x_original[$j + 1] - $x_original[$j];
		$t = ($x_interpolated[$i] - $x_original[$j]) / $dx;
		$y = (1 - $t) * $y_original[$j] + $t * $y_original[$j + 1] + $t * (1 - $t) * ($a[$j] * (1 - $t) + $b[$j] * $t);
		$y_interpolated[$i] = $y;
	}

	return $y_interpolated;
}

// To validate that this yields the same results as R's stats.spline():
// PHP: print_r(spline_interpolate(array(1, 2, 3, 4), array(1, 3, 2, 1), array(1.5, 2.5)));
// R:   spline(c(1, 2, 3, 4), c(1, 3, 2, 1), method="natural", xout=c(1.5, 2.5))
?>