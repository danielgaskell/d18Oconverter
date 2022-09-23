library(dplyr)
library(tidync)
library(jsonlite)

write_json(tidync("legrande_schmidt_2006.nc") %>%
              hyper_filter(depth = depth <= 50) %>%
              hyper_tibble() %>%
              group_by(lon, lat) %>%
              summarize(d18O = mean(d18o)) %>%
              select(d18O, lat, lon),
          "legrande_top50.json")

write_json(tidync("legrande_schmidt_2006.nc") %>%
              hyper_filter(depth = depth == 0) %>%
              hyper_tibble() %>%
              rename(d18O = d18o) %>%
              select(d18O, lat, lon),
          "legrande_0.json")

write_json(tidync("legrande_schmidt_2006.nc") %>%
              hyper_filter(depth = depth == 50) %>%
              hyper_tibble() %>%
              rename(d18O = d18o) %>%
              select(d18O, lat, lon),
          "legrande_50.json")

write_json(tidync("legrande_schmidt_2006.nc") %>%
              hyper_filter(depth = depth == 100) %>%
              hyper_tibble() %>%
              rename(d18O = d18o) %>%
              select(d18O, lat, lon),
          "legrande_100.json")

write_json(tidync("legrande_schmidt_2006.nc") %>%
              hyper_filter(depth = depth == 200) %>%
              hyper_tibble() %>%
              rename(d18O = d18o) %>%
              select(d18O, lat, lon),
          "legrande_200.json")

write_json(tidync("legrande_schmidt_2006.nc") %>%
              hyper_filter(depth = depth == 500) %>%
              hyper_tibble() %>%
              rename(d18O = d18o) %>%
              select(d18O, lat, lon),
          "legrande_500.json")

write_json(tidync("legrande_schmidt_2006.nc") %>%
               hyper_filter(depth = depth == 1000) %>%
               hyper_tibble() %>%
               rename(d18O = d18o) %>%
               select(d18O, lat, lon),
           "legrande_1000.json")

write_json(tidync("legrande_schmidt_2006.nc") %>%
               hyper_filter(depth = depth == 1500) %>%
               hyper_tibble() %>%
               rename(d18O = d18o) %>%
               select(d18O, lat, lon),
           "legrande_1500.json")

write_json(tidync("legrande_schmidt_2006.nc") %>%
               hyper_filter(depth = depth == 2000) %>%
               hyper_tibble() %>%
               rename(d18O = d18o) %>%
               select(d18O, lat, lon),
           "legrande_2000.json")

write_json(tidync("legrande_schmidt_2006.nc") %>%
               hyper_filter(depth = depth == 3000) %>%
               hyper_tibble() %>%
               rename(d18O = d18o) %>%
               select(d18O, lat, lon),
           "legrande_3000.json")

write_json(tidync("legrande_schmidt_2006.nc") %>%
               hyper_filter(depth = depth == 4000) %>%
               hyper_tibble() %>%
               rename(d18O = d18o) %>%
               select(d18O, lat, lon),
           "legrande_4000.json")

write_json(tidync("legrande_schmidt_2006.nc") %>%
               hyper_filter(depth = depth == 5000) %>%
               hyper_tibble() %>%
               rename(d18O = d18o) %>%
               select(d18O, lat, lon),
           "legrande_5000.json")

paste(round((tidync("legrande_schmidt_2006.nc") %>%
                 hyper_filter(depth = depth <= 50) %>%
                 hyper_tibble() %>%
                 group_by(lon, lat) %>%
                 summarize(d18O = mean(d18o, na.rm=T)) %>%
                 mutate(latbin = floor(lat / 10)) %>%
                 group_by(latbin) %>%
                 summarize(d18O = median(d18O, na.rm=T)))$d18O, 2), collapse=", ")