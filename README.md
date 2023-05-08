# Online &delta;<sup>18</sup>O-temperature converter tool

This repository contains the source code for the online &delta;<sup>18</sup>O-temperature converter tool described in:

> Gaskell, D.E., and Hull, P.M., 2022, Technical note: A new online tool for &delta;<sup>18</sup>O-temperature conversions: Climate of the Past Discussions (preprint, in review), doi:10.5194/cp-2022-74.

The current official online host for the tool is [https://research.peabody.yale.edu/d180/](https://research.peabody.yale.edu/d180/). An alternate host is also available at [https://www.danielgaskell.com/d18O](https://www.danielgaskell.com/d18O). This tool may be freely used to generate data for publications provided this publication (or its subsequent version of record) is appropriately cited.

# Changelog

## Version 1.2

Follow-up release for publication.

* Added a workaround for servers which refuse GPlates' certs
* Refactored linear interpolation routine for ~4x performance increase
* Removed reformulation notices from some calibrations where coefficients were
  identical to the original publication
* Minor typo corrections

## Version 1.1

Follow-up release for review.

* Added option to download citations as BibTeX
* Added Meckler et al. (2022) &delta;<sup>18</sup>O<sub>sw</sub> and temperature
  records
* Added column definitions to output
* Added option to select d18Osw_spatial points by great circle distance
* Added number of points averaged and standard deviation to output of
  d18Osw_spatial methods
* Rephrased methods output to better clarify reformulations
* Deprioritized "outside calibration limits" error when other errors are present
* Versioning system
* Fixed minor typos
* Changed the algebraic format of alpha-based calibrations to more precisely
  match the format given in the paper
* Removed Zhou & Zheng (2003) aragonite calibration due to uncertainties about
  which standard conversions should be used with this calibration
* Corrected an issue with the assumed timescale of Rohling et al. (2021) data
* Changed a few default selections to reduce server load

## Version 1.0

Initial release for preprint.

# License

Copyright (c) 2023, Daniel E. Gaskell and Pincelli M. Hull.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

- The above copyright notice and this permission notice shall be included
  in all copies or substantial portions of the Software.
- Any publications making use of the Software or any substantial portions
  thereof shall cite the Software's original publication:

> Gaskell, D.E., and Hull, P.M., 2022, Technical note: A new online tool for &delta;<sup>18</sup>O-temperature conversions: Climate of the Past Discussions (preprint), doi:10.5194/cp-2022-74.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.